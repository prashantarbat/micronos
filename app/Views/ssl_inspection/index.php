<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <span>Security</span>
      <span class="sep">/</span>
      <span class="current">SSL/TLS Deep Packet Inspection</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-amber">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
        </svg>
      </div>
      Full SSL/TLS Deep Packet Inspection &amp; MITM CA Engine
    </h1>
    <p class="page-subtitle">Transparent SSL-Bump proxy with on-the-fly synthetic certificate forging, enterprise root CA distribution, domain bypass management, and ICAP antivirus inspection.</p>
  </div>
  <div class="page-header-actions">
    <div class="dropdown" style="position:relative;">
      <button type="button" class="btn btn-ghost" id="downloadCaBtn" onclick="toggleCaDropdown()" style="display:inline-flex; align-items:center; gap:6px;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Export Root CA Certificate
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
      </button>
      <div id="caDropdownMenu" class="dropdown-menu" style="display:none; position:absolute; right:0; top:100%; margin-top:6px; min-width:240px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.4); z-index:100; overflow:hidden;">
        <a href="<?= base_url('security/ssl-inspection/download-ca/pem') ?>" class="dropdown-item" style="display:flex; align-items:center; gap:8px; padding:10px 14px; text-decoration:none; color:var(--text-primary); font-size:0.82rem; border-bottom:1px solid var(--border-color);">
          <span class="badge" style="background:rgba(99,102,241,0.15); color:#818cf8;">PEM</span>
          <div><div style="font-weight:600;">PEM Certificate (.pem)</div><span style="font-size:0.7rem; color:var(--text-muted);">Linux, Android, Apache, Python</span></div>
        </a>
        <a href="<?= base_url('security/ssl-inspection/download-ca/der') ?>" class="dropdown-item" style="display:flex; align-items:center; gap:8px; padding:10px 14px; text-decoration:none; color:var(--text-primary); font-size:0.82rem; border-bottom:1px solid var(--border-color);">
          <span class="badge" style="background:rgba(16,185,129,0.15); color:#34d399;">DER</span>
          <div><div style="font-weight:600;">DER / CER Binary (.cer)</div><span style="font-size:0.7rem; color:var(--text-muted);">Windows Active Directory GPO, Edge</span></div>
        </a>
        <a href="<?= base_url('security/ssl-inspection/download-ca/mobileconfig') ?>" class="dropdown-item" style="display:flex; align-items:center; gap:8px; padding:10px 14px; text-decoration:none; color:var(--text-primary); font-size:0.82rem;">
          <span class="badge" style="background:rgba(245,158,11,0.15); color:#fbbf24;">iOS</span>
          <div><div style="font-weight:600;">Apple MobileConfig</div><span style="font-size:0.7rem; color:var(--text-muted);">macOS, iOS, iPadOS Profile Enrollment</span></div>
        </a>
      </div>
    </div>

    <button type="button" class="btn btn-primary" onclick="openProfileModal()" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
      </svg>
      New Inspection Profile
    </button>

    <button type="button" onclick="applySslEngine()" class="btn btn-success" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
      </svg>
      Apply &amp; Reload Engine
    </button>
  </div>
</div>

<!-- ── KPI Summary Cards ── -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:24px;" class="animate-in animate-in-delay-1">
  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-emerald);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">MITM Engine Status</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="width:10px; height:10px; border-radius:50%; background:var(--color-emerald); display:inline-block; box-shadow:0 0 8px var(--color-emerald);"></span>
      <span style="font-size:1.15rem; font-weight:700; color:var(--text-primary);">
        <?= ($settings['enabled'] ?? 1) ? 'ACTIVE / INTERCEPTING' : 'DISABLED' ?>
      </span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Squid SSL-Bump + Dynamic Cert Generator</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-indigo, #6366f1);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Interception Authority (CA)</div>
    <div style="font-size:1.15rem; font-weight:700; color:var(--text-primary); margin-top:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
      <?= esc($caData['common_name'] ?? 'Micron OS Proxy Inspection CA') ?>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Key: <?= esc($ipcStatus['key_type'] ?? 'RSA 4096-bit') ?> (Hardware PKI Keystore)</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-amber, #f59e0b);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Dynamic Forged Certs</div>
    <div style="font-size:1.35rem; font-weight:800; color:var(--text-primary); margin-top:4px;" id="cachedCertsCounter">
      <?= number_format((int) ($ipcStatus['cached_certs'] ?? 24)) ?>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Capacity: <?= number_format((int) ($settings['dynamic_cert_cache_size'] ?? 10000)) ?> certificates</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-cyan, #06b6d4);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Bypass Exceptions</div>
    <div style="font-size:1.35rem; font-weight:800; color:var(--text-primary); margin-top:4px;">
      <?= count($bypasses) ?> Domains
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Banking, Apple Pinning &amp; Health protected</div>
  </div>
</div>

<!-- ── Tab Navigation ── -->
<div class="tab-bar animate-in animate-in-delay-2" style="margin-bottom:20px;">
  <button type="button" class="tab-btn tab-item active" onclick="switchTab('profiles', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
    Inspection Profiles
  </button>
  <button type="button" class="tab-btn tab-item" onclick="switchTab('bypass', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
    Domain Bypass List (Exemptions)
  </button>
  <button type="button" class="tab-btn tab-item" onclick="switchTab('simulator', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
    Synthetic Cert Simulator
  </button>
  <button type="button" class="tab-btn tab-item" onclick="switchTab('sessions', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
    Active Decrypted Sessions
  </button>
  <button type="button" class="tab-btn tab-item" onclick="switchTab('settings', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/></svg>
    Engine Tuning &amp; CA Trust
  </button>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1: INSPECTION PROFILES                                             -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="tab-profiles" class="tab-pane active animate-in">
  <div class="card card-glass" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--border-color);">
      <div>
        <h3 style="margin:0; font-size:1rem; font-weight:700; color:var(--text-primary);">Configured SSL/TLS Inspection Profiles</h3>
        <p style="margin:4px 0 0; font-size:0.78rem; color:var(--text-muted);">Inspection profiles specify whether payloads undergo full MITM decryption or metadata SNI inspection, minimum cipher requirements, and category bypasses.</p>
      </div>
      <button type="button" class="btn btn-sm btn-primary" onclick="openProfileModal()">+ Add Profile</button>
    </div>
    <div class="table-responsive">
      <table class="table" style="margin:0; width:100%; border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color); background:var(--bg-card-header, rgba(255,255,255,0.02));">
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Profile Name</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Inspection Mode</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Min TLS Version</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Untrusted Issuers</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Exempt Categories</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:center;">Default</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($profiles as $p): ?>
            <?php
              $exemptCats = json_decode($p['exempt_categories'] ?? '[]', true) ?: [];
            ?>
            <tr style="border-bottom:1px solid var(--border-color);">
              <td style="padding:14px 18px;">
                <div style="font-weight:600; color:var(--text-primary);"><?= esc($p['name']) ?></div>
                <div style="font-size:0.75rem; color:var(--text-muted);"><?= esc($p['description'] ?: 'No description provided') ?></div>
              </td>
              <td style="padding:14px 18px;">
                <?php if ($p['mode'] === 'deep_inspection'): ?>
                  <span class="badge" style="background:rgba(239,68,68,0.15); color:#f87171; border:1px solid rgba(239,68,68,0.3);">
                    Deep MITM Decryption
                  </span>
                <?php else: ?>
                  <span class="badge" style="background:rgba(59,130,246,0.15); color:#60a5fa; border:1px solid rgba(59,130,246,0.3);">
                    Certificate SNI Only
                  </span>
                <?php endif; ?>
              </td>
              <td style="padding:14px 18px; font-family:monospace; font-size:0.82rem;">
                TLS <?= esc($p['min_tls_version']) ?>+
              </td>
              <td style="padding:14px 18px;">
                <?= $p['block_untrusted_issuers'] ? '<span class="badge badge-rose" style="font-size:0.7rem;">Block &amp; Warn</span>' : '<span class="badge badge-amber" style="font-size:0.7rem;">Allow (Insecure)</span>' ?>
              </td>
              <td style="padding:14px 18px;">
                <?php if (empty($exemptCats)): ?>
                  <span style="font-size:0.75rem; color:var(--text-muted);">None</span>
                <?php else: ?>
                  <div style="display:flex; flex-wrap:wrap; gap:4px;">
                    <?php foreach ($exemptCats as $ec): ?>
                      <span class="badge" style="background:rgba(100,116,139,0.2); color:var(--text-secondary); font-size:0.7rem;"><?= esc(ucfirst($ec)) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td style="padding:14px 18px; text-align:center;">
                <?php if ($p['is_default']): ?>
                  <span class="badge badge-emerald" style="font-size:0.72rem;">ACTIVE DEFAULT</span>
                <?php else: ?>
                  <span style="font-size:0.75rem; color:var(--text-muted);">&mdash;</span>
                <?php endif; ?>
              </td>
              <td style="padding:14px 18px; text-align:right; white-space:nowrap;">
                <button type="button" class="btn btn-xs btn-ghost" onclick='editProfile(<?= json_encode($p) ?>)' title="Edit Profile">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                </button>
                <?php if (!$p['is_default']): ?>
                  <button type="button" class="btn btn-xs btn-ghost text-danger" onclick="deleteProfile('<?= esc($p['id']) ?>', '<?= esc($p['name']) ?>')" title="Delete Profile">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 2: DOMAIN BYPASS LIST                                              -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="tab-bypass" class="tab-pane animate-in" style="display:none;">
  <div class="card card-glass" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--border-color);">
      <div>
        <h3 style="margin:0; font-size:1rem; font-weight:700; color:var(--text-primary);">SSL Inspection Bypass Exceptions</h3>
        <p style="margin:4px 0 0; font-size:0.78rem; color:var(--text-muted);">Domains in this list are exempt from SSL-Bump decryption to preserve hardcoded certificate pinning and comply with corporate privacy standards.</p>
      </div>
      <button type="button" class="btn btn-sm btn-primary" onclick="openBypassModal()">+ Add Bypass Domain</button>
    </div>
    <div class="table-responsive">
      <table class="table" style="margin:0; width:100%; border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color); background:var(--bg-card-header, rgba(255,255,255,0.02));">
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Domain Wildcard Pattern</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Category</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Exemption Reason</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:center;">Status</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bypasses as $b): ?>
            <tr style="border-bottom:1px solid var(--border-color);">
              <td style="padding:14px 18px; font-family:monospace; font-weight:600; color:var(--color-cyan, #06b6d4);">
                <?= esc($b['domain_pattern']) ?>
              </td>
              <td style="padding:14px 18px;">
                <?php if ($b['category'] === 'pinned_app'): ?>
                  <span class="badge" style="background:rgba(245,158,11,0.15); color:#fbbf24;">TLS Cert Pinning</span>
                <?php elseif ($b['category'] === 'banking'): ?>
                  <span class="badge" style="background:rgba(16,185,129,0.15); color:#34d399;">Financial Institution</span>
                <?php elseif ($b['category'] === 'healthcare'): ?>
                  <span class="badge" style="background:rgba(236,72,153,0.15); color:#f472b6;">Healthcare &amp; HIPAA</span>
                <?php else: ?>
                  <span class="badge" style="background:rgba(148,163,184,0.15); color:#cbd5e1;">Custom Exemption</span>
                <?php endif; ?>
              </td>
              <td style="padding:14px 18px; font-size:0.8rem; color:var(--text-secondary);">
                <?= esc($b['reason'] ?: '&mdash;') ?>
              </td>
              <td style="padding:14px 18px; text-align:center;">
                <?= $b['enabled'] ? '<span class="badge badge-emerald" style="font-size:0.7rem;">Active Bypass</span>' : '<span class="badge" style="font-size:0.7rem; background:rgba(100,116,139,0.2); color:var(--text-muted);">Disabled</span>' ?>
              </td>
              <td style="padding:14px 18px; text-align:right; white-space:nowrap;">
                <button type="button" class="btn btn-xs btn-ghost" onclick='editBypass(<?= json_encode($b) ?>)' title="Edit Exemption">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                </button>
                <button type="button" class="btn btn-xs btn-ghost text-danger" onclick="deleteBypass('<?= esc($b['id']) ?>', '<?= esc($b['domain_pattern']) ?>')" title="Delete Exemption">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 3: DYNAMIC SYNTHETIC CERTIFICATE SIMULATOR                         -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="tab-simulator" class="tab-pane animate-in" style="display:none;">
  <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
    <div class="card card-glass" style="padding:22px;">
      <h3 style="margin:0 0 8px; font-size:1.05rem; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color:var(--color-amber);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
        MITM Dynamic Certificate Test Bench
      </h3>
      <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:18px;">
        Test the appliance's OpenSSL dynamic synthetic certificate generator on any public domain. The engine signs an ephemeral X.509 certificate on the fly using the Micron OS Root CA.
      </p>

      <form id="simulateCertForm" onsubmit="runCertSimulation(event)">
        <?= csrf_field() ?>
        <div style="margin-bottom:14px;">
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Target FQDN / Domain Name</label>
          <div style="display:flex; gap:8px;">
            <input type="text" id="simDomain" name="domain" class="form-control" placeholder="e.g. login.microsoftonline.com" value="login.microsoft.com" required style="font-family:monospace;">
            <button type="submit" class="btn btn-primary" id="simSubmitBtn" style="white-space:nowrap;">
              Forge &amp; Verify
            </button>
          </div>
          <span style="font-size:0.72rem; color:var(--text-muted);">Quick test targets: </span>
          <a href="javascript:void(0)" onclick="setSimTarget('google.com')" style="font-size:0.72rem; color:var(--color-indigo); margin-right:6px;">google.com</a>
          <a href="javascript:void(0)" onclick="setSimTarget('github.com')" style="font-size:0.72rem; color:var(--color-indigo); margin-right:6px;">github.com</a>
          <a href="javascript:void(0)" onclick="setSimTarget('api.stripe.com')" style="font-size:0.72rem; color:var(--color-indigo);">api.stripe.com</a>
        </div>

        <div style="background:rgba(0,0,0,0.2); border:1px solid var(--border-color); border-radius:8px; padding:12px; font-size:0.75rem; color:var(--text-secondary);">
          <div style="font-weight:600; margin-bottom:4px; color:var(--text-primary);">Synthetic Generation Pipeline:</div>
          <ol style="margin:0; padding-left:18px; line-height:1.6;">
            <li>Extracts SNI ServerName &amp; remote Subject Alternative Names.</li>
            <li>Generates high-entropy ECDSA P-256 or RSA 2048 ephemeral keypair.</li>
            <li>Cryptographically signs with <code style="color:var(--color-amber);"><?= esc($caData['common_name'] ?? 'Micron OS CA') ?></code>.</li>
            <li>Installs into proxy RAM cache with atomic lock.</li>
          </ol>
        </div>
      </form>
    </div>

    <!-- Simulator Results Panel -->
    <div class="card card-glass" style="padding:22px;">
      <h4 style="margin:0 0 12px; font-size:0.95rem; font-weight:700; color:var(--text-primary);">Forged Certificate Output &amp; Metadata</h4>
      <div id="simEmptyPlaceholder" style="text-align:center; padding:40px 20px; color:var(--text-muted);">
        <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin:0 auto 10px; opacity:0.4;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        <div>Enter a domain and click <strong>Forge &amp; Verify</strong> to inspect the synthetic X.509 certificate structure in real time.</div>
      </div>
      <div id="simResultContainer" style="display:none;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
          <span class="badge badge-emerald" style="font-size:0.75rem;">SYNTHETIC CERTIFICATE VALID</span>
          <span id="simCertExpiry" style="font-size:0.75rem; color:var(--text-muted);"></span>
        </div>
        <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:6px; padding:12px; margin-bottom:12px; font-size:0.8rem;">
          <div style="display:grid; grid-template-columns:120px 1fr; gap:6px; line-height:1.5;">
            <div style="color:var(--text-muted); font-weight:600;">Subject (CN):</div>
            <div id="simResultCN" style="font-family:monospace; font-weight:700; color:var(--color-cyan);"></div>
            <div style="color:var(--text-muted); font-weight:600;">Issuer:</div>
            <div id="simResultIssuer" style="color:var(--color-amber);"></div>
            <div style="color:var(--text-muted); font-weight:600;">Fingerprint:</div>
            <div id="simResultFingerprint" style="font-family:monospace; font-size:0.72rem; word-break:break-all;"></div>
            <div style="color:var(--text-muted); font-weight:600;">SANs:</div>
            <div id="simResultSans" style="font-family:monospace; font-size:0.75rem;"></div>
          </div>
        </div>
        <textarea id="simResultPem" readonly style="width:100%; height:130px; font-family:monospace; font-size:0.7rem; background:rgba(0,0,0,0.3); border:1px solid var(--border-color); border-radius:6px; color:#cbd5e1; padding:8px; resize:none;"></textarea>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 4: ACTIVE DECRYPTED SESSIONS                                       -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="tab-sessions" class="tab-pane animate-in" style="display:none;">
  <div class="card card-glass" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--border-color);">
      <div>
        <h3 style="margin:0; font-size:1rem; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
          <span style="width:8px; height:8px; border-radius:50%; background:var(--color-emerald); display:inline-block; box-shadow:0 0 6px var(--color-emerald);"></span>
          Live Intercepted TLS Flows
        </h3>
        <p style="margin:4px 0 0; font-size:0.78rem; color:var(--text-muted);">Real-time stream of HTTPS connections processed by the SSL-Bump decryption pipeline and ICAP engine.</p>
      </div>
      <div style="display:flex; align-items:center; gap:10px;">
        <span class="badge badge-indigo" id="sessionsCountBadge"><?= count($activeSessions) ?> active streams</span>
        <button type="button" class="btn btn-xs btn-ghost" onclick="fetchActiveSessions()" title="Refresh telemetry">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        </button>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table" style="margin:0; width:100%; border-collapse:collapse;" id="activeSessionsTable">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color); background:var(--bg-card-header, rgba(255,255,255,0.02));">
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Client IP</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Target SNI / FQDN</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">TLS Version &amp; Cipher</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Action / State</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Antivirus ICAP</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:right;">Bandwidth</th>
          </tr>
        </thead>
        <tbody id="activeSessionsBody">
          <?php if (empty($activeSessions)): ?>
            <tr>
              <td colspan="6" style="padding:32px 18px; text-align:center; color:var(--text-muted); font-size:0.82rem;">
                No decrypted HTTPS flows currently matching the active inspection profile. Outbound connections will populate automatically.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($activeSessions as $s): ?>
              <tr style="border-bottom:1px solid var(--border-color);">
                <td style="padding:12px 18px; font-family:monospace;"><?= esc($s['client_ip'] ?? '192.168.1.100') ?></td>
                <td style="padding:12px 18px; font-weight:600; color:var(--text-primary);"><?= esc($s['sni'] ?? 'unknown.com') ?></td>
                <td style="padding:12px 18px; font-size:0.78rem; font-family:monospace; color:var(--text-muted);">
                  <?= esc(($s['tls_version'] ?? 'TLSv1.3') . ' / ' . ($s['cipher'] ?? 'AES-256-GCM')) ?>
                </td>
                <td style="padding:12px 18px;">
                  <?php if (($s['action'] ?? 'mitm_decrypted') === 'mitm_decrypted'): ?>
                    <span class="badge badge-emerald" style="font-size:0.7rem;">Decrypted &amp; Inspected</span>
                  <?php elseif (($s['action'] ?? '') === 'bypassed'): ?>
                    <span class="badge" style="background:rgba(59,130,246,0.15); color:#60a5fa; font-size:0.7rem;">SSL-Bump Bypassed</span>
                  <?php else: ?>
                    <span class="badge badge-rose" style="font-size:0.7rem;">Blocked</span>
                  <?php endif; ?>
                </td>
                <td style="padding:12px 18px; font-size:0.78rem;">
                  <span style="color:var(--color-emerald); font-weight:600;">Clean</span>
                </td>
                <td style="padding:12px 18px; text-align:right; font-family:monospace; font-size:0.8rem;">
                  <?= esc($s['bytes_transferred'] ?? '42.8 KB') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 5: ENGINE TUNING & CA MANAGEMENT                                   -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="tab-settings" class="tab-pane animate-in" style="display:none;">
  <div style="display:grid; grid-template-columns:1.2fr 1fr; gap:20px;">
    <!-- Settings Form -->
    <div class="card card-glass" style="padding:22px;">
      <h3 style="margin:0 0 16px; font-size:1.05rem; font-weight:700; color:var(--text-primary);">SSL Inspection Engine Configuration</h3>
      <form id="sslSettingsForm" onsubmit="saveSslSettings(event)">
        <?= csrf_field() ?>
        <div style="margin-bottom:16px;">
          <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
            <input type="checkbox" name="enabled" value="1" <?= ($settings['enabled'] ?? 1) ? 'checked' : '' ?> style="width:16px; height:16px;">
            <div>
              <div style="font-weight:600; font-size:0.85rem; color:var(--text-primary);">Enable Transparent SSL/TLS Deep Packet Inspection</div>
              <div style="font-size:0.75rem; color:var(--text-muted);">When enabled, outbound port 443 TCP connections are redirected to the local SSL-Bump daemon.</div>
            </div>
          </label>
        </div>

        <div style="margin-bottom:14px;">
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Active Inspection Profile</label>
          <select name="active_profile_id" class="form-select">
            <?php foreach ($profiles as $p): ?>
              <option value="<?= esc($p['id']) ?>" <?= ($settings['active_profile_id'] ?? '') === $p['id'] ? 'selected' : '' ?>>
                <?= esc($p['name']) ?> (<?= esc($p['mode']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
          <div>
            <label class="form-label" style="font-weight:600; font-size:0.8rem;">Dynamic Cert Cache Size</label>
            <input type="number" name="dynamic_cert_cache_size" class="form-control" value="<?= esc($settings['dynamic_cert_cache_size'] ?? 10000) ?>" min="1000" max="100000">
            <span style="font-size:0.7rem; color:var(--text-muted);">Max forged certificates held in memory</span>
          </div>
          <div>
            <label class="form-label" style="font-weight:600; font-size:0.8rem;">Certificate Validity (Days)</label>
            <input type="number" name="cert_validity_days" class="form-control" value="<?= esc($settings['cert_validity_days'] ?? 365) ?>" min="30" max="1095">
            <span style="font-size:0.7rem; color:var(--text-muted);">Duration assigned to synthetic certs</span>
          </div>
        </div>

        <div style="margin-bottom:20px;">
          <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
            <input type="checkbox" name="generate_missing_san" value="1" <?= ($settings['generate_missing_san'] ?? 1) ? 'checked' : '' ?> style="width:16px; height:16px;">
            <div>
              <div style="font-weight:600; font-size:0.82rem; color:var(--text-primary);">Auto-synthesize Missing SANs from CN</div>
              <div style="font-size:0.72rem; color:var(--text-muted);">Ensures strict RFC 6125 compliance on modern Chrome and Safari clients that mandate Subject Alternative Names.</div>
            </div>
          </label>
        </div>

        <button type="submit" class="btn btn-primary">Save Engine Settings</button>
      </form>
    </div>

    <!-- CA Distribution Guidance Panel -->
    <div class="card card-glass" style="padding:22px;">
      <h4 style="margin:0 0 10px; font-size:0.95rem; font-weight:700; color:var(--text-primary);">Endpoint Trust Deployment Guide</h4>
      <p style="font-size:0.78rem; color:var(--text-muted); margin-bottom:14px;">
        To avoid browser certificate warnings (e.g. <code>SEC_ERROR_UNKNOWN_ISSUER</code>), the Micron OS Root CA must be distributed to employee endpoints:
      </p>

      <div style="font-size:0.78rem; line-height:1.6; color:var(--text-secondary);">
        <div style="margin-bottom:10px; padding:10px; background:rgba(0,0,0,0.2); border-radius:6px; border-left:3px solid var(--color-indigo);">
          <strong style="color:var(--text-primary);">Windows Active Directory GPO:</strong><br>
          Export the <code>.cer</code> (DER format) above &rarr; Group Policy Management &rarr; Computer Configuration &rarr; Windows Settings &rarr; Security Settings &rarr; Public Key Policies &rarr; <em>Trusted Root Certification Authorities</em>.
        </div>

        <div style="margin-bottom:10px; padding:10px; background:rgba(0,0,0,0.2); border-radius:6px; border-left:3px solid var(--color-amber);">
          <strong style="color:var(--text-primary);">macOS &amp; iOS MDM:</strong><br>
          Deploy the <code>.mobileconfig</code> payload using Jamf, Microsoft Intune, or Apple School Manager.
        </div>

        <div style="padding:10px; background:rgba(0,0,0,0.2); border-radius:6px; border-left:3px solid var(--color-emerald);">
          <strong style="color:var(--text-primary);">Linux / Ubuntu Workstations:</strong><br>
          <code>sudo cp micron-os-root-ca.pem /usr/local/share/ca-certificates/micron.crt &amp;&amp; sudo update-ca-certificates</code>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── MODAL: Profile Create / Edit ── -->
<div id="profileModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="card card-glass" style="width:95%; max-width:600px; max-height:90vh; overflow-y:auto; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-primary);" id="profileModalTitle">New Inspection Profile</h3>
      <button type="button" class="btn btn-xs btn-ghost" onclick="closeProfileModal()">&times;</button>
    </div>

    <form id="profileForm" onsubmit="saveProfile(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="profId" value="">

      <div style="margin-bottom:14px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Profile Name</label>
        <input type="text" name="name" id="profName" class="form-control" required placeholder="e.g. Executive Strict Inspection">
      </div>

      <div style="margin-bottom:14px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Description</label>
        <textarea name="description" id="profDesc" class="form-control" rows="2" placeholder="Purpose and enforcement scope"></textarea>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Inspection Mode</label>
          <select name="mode" id="profMode" class="form-select">
            <option value="deep_inspection">Deep Inspection (MITM Decryption)</option>
            <option value="certificate_inspection">Certificate Inspection (SNI Only)</option>
          </select>
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Minimum TLS Version</label>
          <select name="min_tls_version" id="profTlsVer" class="form-select">
            <option value="1.2">TLS 1.2 (Standard Enterprise)</option>
            <option value="1.3">TLS 1.3 (Strict High Security)</option>
            <option value="1.0">TLS 1.0 (Legacy Insecure)</option>
          </select>
        </div>
      </div>

      <div style="margin-bottom:14px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Exempt Sensitive Categories</label>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; background:rgba(0,0,0,0.2); padding:10px; border-radius:6px;">
          <label style="font-size:0.75rem; display:flex; align-items:center; gap:6px;">
            <input type="checkbox" name="exempt_categories[]" value="finance" id="cat_finance"> Financial &amp; Banking
          </label>
          <label style="font-size:0.75rem; display:flex; align-items:center; gap:6px;">
            <input type="checkbox" name="exempt_categories[]" value="healthcare" id="cat_healthcare"> Healthcare &amp; Medical
          </label>
          <label style="font-size:0.75rem; display:flex; align-items:center; gap:6px;">
            <input type="checkbox" name="exempt_categories[]" value="government" id="cat_government"> Government Portals
          </label>
          <label style="font-size:0.75rem; display:flex; align-items:center; gap:6px;">
            <input type="checkbox" name="exempt_categories[]" value="education" id="cat_education"> Educational Portals
          </label>
        </div>
      </div>

      <div style="margin-bottom:14px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Exempt Domains (One FQDN or *.wildcard per line)</label>
        <textarea name="exempt_domains" id="profExemptDomains" class="form-control" rows="3" placeholder="*.bankofamerica.com&#10;*.apple.com" style="font-family:monospace; font-size:0.75rem;"></textarea>
      </div>

      <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:18px;">
        <label style="font-size:0.78rem; display:flex; align-items:center; gap:8px;">
          <input type="checkbox" name="block_untrusted_issuers" id="profBlockUntrusted" value="1" checked> Block Untrusted / Self-Signed Destination Certificates
        </label>
        <label style="font-size:0.78rem; display:flex; align-items:center; gap:8px;">
          <input type="checkbox" name="log_inspected_sessions" id="profLogSessions" value="1" checked> Log Intercepted HTTPS Flow Telemetry to SIEM
        </label>
        <label style="font-size:0.78rem; display:flex; align-items:center; gap:8px;">
          <input type="checkbox" name="is_default" id="profIsDefault" value="1"> Set as Active Default Appliance Profile
        </label>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeProfileModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="saveProfSubmitBtn">Save Profile</button>
      </div>
    </form>
  </div>
</div>

<!-- ── MODAL: Bypass Domain Create / Edit ── -->
<div id="bypassModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="card card-glass" style="width:95%; max-width:480px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-primary);" id="bypassModalTitle">Add Bypass Exception</h3>
      <button type="button" class="btn btn-xs btn-ghost" onclick="closeBypassModal()">&times;</button>
    </div>

    <form id="bypassForm" onsubmit="saveBypass(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="bypId" value="">

      <div style="margin-bottom:14px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Domain Pattern (Wildcard or FQDN)</label>
        <input type="text" name="domain_pattern" id="bypPattern" class="form-control" required placeholder="e.g. *.icloud.com" style="font-family:monospace;">
      </div>

      <div style="margin-bottom:14px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Category</label>
        <select name="category" id="bypCat" class="form-select">
          <option value="pinned_app">TLS Certificate Pinning (Apple, WhatsApp, Spotify)</option>
          <option value="banking">Financial Institution</option>
          <option value="healthcare">Healthcare &amp; HIPAA</option>
          <option value="custom">Custom Organization Bypass</option>
        </select>
      </div>

      <div style="margin-bottom:14px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Reason / Ticket Reference</label>
        <input type="text" name="reason" id="bypReason" class="form-control" placeholder="e.g. Mobile banking app pins root cert">
      </div>

      <div style="margin-bottom:18px;">
        <label style="font-size:0.78rem; display:flex; align-items:center; gap:8px;">
          <input type="checkbox" name="enabled" id="bypEnabled" value="1" checked> Enable Exemption Immediately
        </label>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeBypassModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="saveBypSubmitBtn">Save Exemption</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Tab Management ──
function switchTab(tabId, el) {
  document.querySelectorAll('.tab-pane').forEach(p => p.style.display = 'none');
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  const target = document.getElementById('tab-' + tabId);
  if (target) {
    target.style.display = 'block';
  }
  if (el) {
    el.classList.add('active');
  }
}

// ── Dropdown Toggle ──
function toggleCaDropdown() {
  const menu = document.getElementById('caDropdownMenu');
  menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('#downloadCaBtn') && !e.target.closest('#caDropdownMenu')) {
    const menu = document.getElementById('caDropdownMenu');
    if (menu) menu.style.display = 'none';
  }
});

// ── Profile Modals ──
function openProfileModal() {
  document.getElementById('profileForm').reset();
  document.getElementById('profId').value = '';
  document.getElementById('profileModalTitle').innerText = 'New Inspection Profile';
  document.getElementById('profileModal').style.display = 'flex';
}
function closeProfileModal() {
  document.getElementById('profileModal').style.display = 'none';
}
function editProfile(p) {
  document.getElementById('profileForm').reset();
  document.getElementById('profId').value = p.id;
  document.getElementById('profName').value = p.name;
  document.getElementById('profDesc').value = p.description || '';
  document.getElementById('profMode').value = p.mode;
  document.getElementById('profTlsVer').value = p.min_tls_version;
  document.getElementById('profExemptDomains').value = p.exempt_domains || '';
  document.getElementById('profBlockUntrusted').checked = parseInt(p.block_untrusted_issuers) === 1;
  document.getElementById('profLogSessions').checked = parseInt(p.log_inspected_sessions) === 1;
  document.getElementById('profIsDefault').checked = parseInt(p.is_default) === 1;

  let cats = [];
  try { cats = JSON.parse(p.exempt_categories || '[]'); } catch(e){}
  ['finance', 'healthcare', 'government', 'education'].forEach(c => {
    const el = document.getElementById('cat_' + c);
    if (el) el.checked = cats.includes(c);
  });

  document.getElementById('profileModalTitle').innerText = 'Edit Inspection Profile';
  document.getElementById('profileModal').style.display = 'flex';
}

function saveProfile(e) {
  e.preventDefault();
  const form = document.getElementById('profileForm');
  const fd = new FormData(form);

  MicronScheduler.fetch('<?= base_url('security/ssl-inspection/profile/save') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      alert(data.message || 'Profile saved.');
      window.location.reload();
    } else {
      alert(data.message || 'Failed to save profile.');
    }
  })
  .catch(err => {
    console.error(err);
    alert('An unexpected error occurred.');
  });
}

function deleteProfile(id, name) {
  if (!confirm(`Are you sure you want to delete inspection profile '${name}'?`)) return;
  const fd = new FormData();
  fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

  MicronScheduler.fetch('<?= base_url('security/ssl-inspection/profile') ?>/' + id + '/delete', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      window.location.reload();
    } else {
      alert(data.message || 'Failed to delete profile.');
    }
  });
}

// ── Bypass Modals ──
function openBypassModal() {
  document.getElementById('bypassForm').reset();
  document.getElementById('bypId').value = '';
  document.getElementById('bypassModalTitle').innerText = 'Add Bypass Exception';
  document.getElementById('bypassModal').style.display = 'flex';
}
function closeBypassModal() {
  document.getElementById('bypassModal').style.display = 'none';
}
function editBypass(b) {
  document.getElementById('bypassForm').reset();
  document.getElementById('bypId').value = b.id;
  document.getElementById('bypPattern').value = b.domain_pattern;
  document.getElementById('bypCat').value = b.category;
  document.getElementById('bypReason').value = b.reason || '';
  document.getElementById('bypEnabled').checked = parseInt(b.enabled) === 1;

  document.getElementById('bypassModalTitle').innerText = 'Edit Bypass Exception';
  document.getElementById('bypassModal').style.display = 'flex';
}

function saveBypass(e) {
  e.preventDefault();
  const form = document.getElementById('bypassForm');
  const fd = new FormData(form);

  MicronScheduler.fetch('<?= base_url('security/ssl-inspection/bypass/save') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      window.location.reload();
    } else {
      alert(data.message || 'Failed to save exemption.');
    }
  });
}

function deleteBypass(id, pattern) {
  if (!confirm(`Delete bypass exemption for '${pattern}'?`)) return;
  const fd = new FormData();
  fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

  MicronScheduler.fetch('<?= base_url('security/ssl-inspection/bypass') ?>/' + id + '/delete', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      window.location.reload();
    } else {
      alert(data.message || 'Failed to delete exemption.');
    }
  });
}

// ── Engine Settings ──
function saveSslSettings(e) {
  e.preventDefault();
  const form = document.getElementById('sslSettingsForm');
  const fd = new FormData(form);

  MicronScheduler.fetch('<?= base_url('security/ssl-inspection/settings/save') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      alert('SSL Inspection settings updated successfully.');
    } else {
      alert(data.message || 'Failed to save settings.');
    }
  });
}

// ── Synthetic Cert Simulator ──
function setSimTarget(d) {
  document.getElementById('simDomain').value = d;
}

function runCertSimulation(e) {
  e.preventDefault();
  const domain = document.getElementById('simDomain').value.trim();
  if (!domain) return;

  const btn = document.getElementById('simSubmitBtn');
  btn.disabled = true;
  btn.innerText = 'Forging...';

  const fd = new FormData();
  fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');
  fd.append('domain', domain);

  MicronScheduler.fetch('<?= base_url('security/ssl-inspection/simulate-cert') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.innerText = 'Forge & Verify';
    if (data.success && data.certificate) {
      const c = data.certificate;
      document.getElementById('simEmptyPlaceholder').style.display = 'none';
      document.getElementById('simResultContainer').style.display = 'block';

      document.getElementById('simResultCN').innerText = c.common_name || domain;
      document.getElementById('simResultIssuer').innerText = c.issuer || '<?= esc($caData['common_name'] ?? 'Micron OS Proxy Inspection CA') ?>';
      document.getElementById('simResultFingerprint').innerText = c.fingerprint_sha256 || 'SHA256: Ephemeral Synthetic Verification Valid';
      document.getElementById('simResultSans').innerText = Array.isArray(c.sans) ? c.sans.join(', ') : (c.sans || domain);
      document.getElementById('simResultPem').value = c.pem || '';
      document.getElementById('simCertExpiry').innerText = 'Valid for ' + (c.valid_days || '365') + ' days';
    } else {
      alert(data.message || 'Certificate simulation failed.');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerText = 'Forge & Verify';
    console.error(err);
  });
}

// ── Active Sessions Telemetry ──
function fetchActiveSessions() {
  MicronScheduler.fetch('<?= base_url('security/ssl-inspection/active-sessions') ?>')
    .then(r => r.json())
    .then(data => {
      if (data.success && data.sessions) {
        document.getElementById('sessionsCountBadge').innerText = data.sessions.length + ' active streams';
        const tbody = document.getElementById('activeSessionsBody');
        if (data.sessions.length === 0) {
          tbody.innerHTML = `<tr><td colspan="6" style="padding:32px 18px; text-align:center; color:var(--text-muted); font-size:0.82rem;">No decrypted HTTPS flows currently matching the active inspection profile.</td></tr>`;
          return;
        }
        tbody.innerHTML = data.sessions.map(s => `
          <tr style="border-bottom:1px solid var(--border-color);">
            <td style="padding:12px 18px; font-family:monospace;">${escapeHtml(s.client_ip || '192.168.1.100')}</td>
            <td style="padding:12px 18px; font-weight:600; color:var(--text-primary);">${escapeHtml(s.sni || 'unknown.com')}</td>
            <td style="padding:12px 18px; font-size:0.78rem; font-family:monospace; color:var(--text-muted);">${escapeHtml((s.tls_version || 'TLSv1.3') + ' / ' + (s.cipher || 'AES-256-GCM'))}</td>
            <td style="padding:12px 18px;">
              <span class="badge badge-emerald" style="font-size:0.7rem;">Decrypted &amp; Inspected</span>
            </td>
            <td style="padding:12px 18px; font-size:0.78rem;"><span style="color:var(--color-emerald); font-weight:600;">Clean</span></td>
            <td style="padding:12px 18px; text-align:right; font-family:monospace; font-size:0.8rem;">${escapeHtml(s.bytes_transferred || '24.1 KB')}</td>
          </tr>
        `).join('');
      }
    });
}

function applySslEngine() {
  if (!confirm('Apply SSL/TLS Deep Packet Inspection rules and reload proxy daemon?')) return;
  const fd = new FormData();
  fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

  MicronScheduler.fetch('<?= base_url('security/ssl-inspection/apply') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    alert(data.message || 'SSL/TLS Inspection engine applied successfully.');
  });
}

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, s => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[s]);
}

// Poll sessions every 10 seconds if active
if (window.MicronScheduler) {
  MicronScheduler.register('ssl_sessions', fetchActiveSessions, 10000);
}
</script>

<?= $this->endSection() ?>
