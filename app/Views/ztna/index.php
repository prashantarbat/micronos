<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <span>VPN &amp; Remote Access</span>
      <span class="sep">/</span>
      <span class="current">Clientless Gateway (ZTNA)</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-indigo">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
        </svg>
      </div>
      In-Browser Clientless Gateway (Zero-Install HTML5 RDP/SSH &amp; Web ZTNA)
    </h1>
    <p class="page-subtitle">Zero-Trust Network Access platform. Publish corporate web apps, Windows Desktops (RDP), Linux Shells (SSH), and consoles (VNC) directly into browser tabs with custom enterprise company branding.</p>
  </div>
  <div class="page-header-actions" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
    <a href="<?= base_url('vpn/clientless-gateway/portal') ?>" target="_blank" class="btn btn-ghost" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
      </svg>
      Open User Portal
    </a>

    <button type="button" class="btn btn-primary" onclick="openResourceModal()" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
      </svg>
      Publish Resource
    </button>

    <button type="button" id="btnApplyZtna" onclick="applyZtnaGateway()" class="btn btn-success" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
      </svg>
      Apply &amp; Reload Proxy
    </button>
  </div>
</div>

<!-- ── Notification Toast Container ── -->
<div id="ztnaAlertContainer" style="margin-bottom:18px; display:none;">
  <div id="ztnaAlert" class="card card-glass" style="padding:12px 18px; display:flex; align-items:center; justify-content:space-between; gap:12px; border-left:4px solid var(--color-indigo);">
    <div style="display:flex; align-items:center; gap:10px;">
      <span id="ztnaAlertIcon" style="font-size:1.1rem;">ℹ️</span>
      <span id="ztnaAlertMsg" style="font-size:0.85rem; font-weight:600; color:var(--text-primary);"></span>
    </div>
    <button type="button" class="btn btn-xs btn-ghost" onclick="document.getElementById('ztnaAlertContainer').style.display='none'">&times;</button>
  </div>
</div>

<!-- ── KPI Summary Cards ── -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:24px;" class="animate-in animate-in-delay-1">
  <div class="card card-glass" style="padding:16px 20px; border-left:3px solid <?= ($settings['enabled'] ?? 1) ? 'var(--color-emerald)' : 'var(--color-rose)' ?>;">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700; letter-spacing:0.05em;">Gateway Core State</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:6px;">
      <span style="width:10px; height:10px; border-radius:50%; background:<?= ($settings['enabled'] ?? 1) ? 'var(--color-emerald)' : 'var(--color-rose)' ?>; display:inline-block; box-shadow:0 0 8px <?= ($settings['enabled'] ?? 1) ? 'var(--color-emerald)' : 'var(--color-rose)' ?>;"></span>
      <span style="font-size:1.15rem; font-weight:800; color:var(--text-primary);">
        <?= ($settings['enabled'] ?? 1) ? 'ONLINE / PROXYING' : 'DISABLED' ?>
      </span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Port <?= esc($settings['external_port'] ?? 443) ?> &bull; Nginx Subrequest + guacd</div>
  </div>

  <div class="card card-glass" style="padding:16px 20px; border-left:3px solid var(--color-indigo, #6366f1);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700; letter-spacing:0.05em;">Published Resources</div>
    <div style="font-size:1.35rem; font-weight:800; color:var(--text-primary); margin-top:4px;">
      <?= count($resources) ?> Targets
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Web Apps, Windows RDP, Linux SSH, VNC</div>
  </div>

  <div class="card card-glass" style="padding:16px 20px; border-left:3px solid var(--color-amber, #f59e0b);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700; letter-spacing:0.05em;">Active Remote Sessions</div>
    <div style="font-size:1.35rem; font-weight:800; color:var(--text-primary); margin-top:4px;">
      <?= count($sessions) ?> Live Streams
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">In-Browser HTML5 Canvas Connections</div>
  </div>

  <div class="card card-glass" style="padding:16px 20px; border-left:3px solid <?= esc($settings['brand_color'] ?? '#6366f1') ?>;">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700; letter-spacing:0.05em;">Portal Identity &amp; Brand</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="width:14px; height:14px; border-radius:4px; background:<?= esc($settings['brand_color'] ?? '#6366f1') ?>; display:inline-block; border:1px solid rgba(255,255,255,0.3);"></span>
      <span style="font-size:1.05rem; font-weight:700; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
        <?= esc($settings['company_name'] ?? 'Micron Security') ?>
      </span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
      <?= esc($settings['external_domain'] ?? 'gateway.micronos.local') ?>:<?= esc($settings['external_port'] ?? 443) ?>
    </div>
  </div>
</div>

<!-- ── Tab Navigation ── -->
<div class="tab-bar ztna-tabs animate-in animate-in-delay-2" style="margin-bottom:20px; display:flex; gap:6px; flex-wrap:wrap;">
  <button type="button" class="tab-item tab-btn active" id="tabBtnResources" onclick="switchZtnaTab('resources', this)">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
    Published Resource Catalogue
  </button>
  <button type="button" class="tab-item tab-btn" id="tabBtnSessions" onclick="switchZtnaTab('sessions', this)">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197"/></svg>
    Active User Sessions (<?= count($sessions) ?>)
  </button>
  <button type="button" class="tab-item tab-btn" id="tabBtnBranding" onclick="switchZtnaTab('branding', this)">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4 5 5 0 013-4.5V7a3 3 0 013-3h4a3 3 0 013 3v5.5a5 5 0 013 4.5 4 4 0 01-4 4H7zM9 7h6M9 11h6"/></svg>
    Company Branding &amp; Portal
  </button>
  <button type="button" class="tab-item tab-btn" id="tabBtnSettings" onclick="switchZtnaTab('settings', this)">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/></svg>
    Gateway Core &amp; guacd Daemon
  </button>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1: PUBLISHED RESOURCE CATALOGUE                                    -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="ztna-tab-resources" class="tab-pane active animate-in">
  <div class="card card-glass" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--border-color); flex-wrap:wrap; gap:12px;">
      <div>
        <h3 style="margin:0; font-size:1rem; font-weight:700; color:var(--text-primary);">Authorized Internal Workspaces &amp; Application Targets</h3>
        <p style="margin:4px 0 0; font-size:0.78rem; color:var(--text-muted);">Users authenticate once at the portal, then launch these targets via reverse proxy or HTML5 canvas stream without VPN client software.</p>
      </div>
      <button type="button" class="btn btn-sm btn-primary" onclick="openResourceModal()" style="display:inline-flex; align-items:center; gap:6px;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Publish Resource
      </button>
    </div>
    <div class="table-responsive">
      <table class="table" style="margin:0; width:100%; border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color); background:var(--bg-card-header, rgba(255,255,255,0.02));">
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Resource Name &amp; URL</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Protocol</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Internal Host &amp; Port</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Allowed RBAC Roles</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:center;">Active Sessions</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody id="resourcesTableBody">
          <?php if (empty($resources)): ?>
            <tr>
              <td colspan="6" style="padding:36px 18px; text-align:center; color:var(--text-muted);">
                <div style="font-size:2rem; margin-bottom:8px; opacity:0.6;">📦</div>
                <div style="font-weight:600; font-size:0.9rem; color:var(--text-primary); margin-bottom:4px;">No Internal Resources Published Yet</div>
                <div style="font-size:0.8rem; margin-bottom:14px;">Publish Windows RDP Desktops, Linux Terminals, or Web Apps to provide zero-install browser access.</div>
                <button type="button" class="btn btn-sm btn-primary" onclick="openResourceModal()">Publish First Resource</button>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($resources as $res): ?>
              <?php $allowed = json_decode($res['allowed_roles'] ?? '[]', true) ?: []; ?>
              <tr style="border-bottom:1px solid var(--border-color);" id="row_<?= esc($res['id']) ?>">
                <td style="padding:14px 18px;">
                  <div style="font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
                    <?php if ($res['type'] === 'rdp'): ?>
                      <span style="font-size:1.15rem;">💻</span>
                    <?php elseif ($res['type'] === 'ssh'): ?>
                      <span style="font-size:1.15rem;">⌨️</span>
                    <?php elseif ($res['type'] === 'vnc'): ?>
                      <span style="font-size:1.15rem;">🖥️</span>
                    <?php else: ?>
                      <span style="font-size:1.15rem;">🌐</span>
                    <?php endif; ?>
                    <?= esc($res['name']) ?>
                  </div>
                  <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px; font-family:monospace;">
                    /apps/<?= esc($res['slug']) ?> &bull; <span style="color:var(--text-secondary);"><?= esc($res['description'] ?: 'Zero trust protected') ?></span>
                  </div>
                </td>
                <td style="padding:14px 18px;">
                  <?php if ($res['type'] === 'rdp'): ?>
                    <span class="badge" style="background:rgba(59,130,246,0.15); color:#60a5fa; font-weight:700;">HTML5 RDP</span>
                  <?php elseif ($res['type'] === 'ssh'): ?>
                    <span class="badge" style="background:rgba(16,185,129,0.15); color:#34d399; font-weight:700;">HTML5 SSH</span>
                  <?php elseif ($res['type'] === 'vnc'): ?>
                    <span class="badge" style="background:rgba(245,158,11,0.15); color:#fbbf24; font-weight:700;">HTML5 VNC</span>
                  <?php else: ?>
                    <span class="badge" style="background:rgba(99,102,241,0.15); color:#818cf8; font-weight:700;">Web Proxy</span>
                  <?php endif; ?>
                </td>
                <td style="padding:14px 18px; font-family:monospace; font-size:0.82rem; color:var(--text-secondary);">
                  <?= esc($res['internal_host']) ?>:<?= esc($res['internal_port']) ?>
                </td>
                <td style="padding:14px 18px;">
                  <div style="display:flex; flex-wrap:wrap; gap:4px; max-width:260px;">
                    <?php if (empty($allowed)): ?>
                      <span class="badge" style="background:rgba(100,116,139,0.2); font-size:0.7rem;">All Authenticated Users</span>
                    <?php else: ?>
                      <?php foreach ($allowed as $rl): ?>
                        <span class="badge badge-indigo" style="font-size:0.7rem;"><?= esc($rl) ?></span>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </td>
                <td style="padding:14px 18px; text-align:center;">
                  <span class="badge <?= (int)$res['active_sessions_count'] > 0 ? 'badge-emerald' : '' ?>" style="font-size:0.75rem;">
                    <?= (int)$res['active_sessions_count'] ?> active
                  </span>
                </td>
                <td style="padding:14px 18px; text-align:right; white-space:nowrap;">
                  <a href="<?= base_url('vpn/clientless-gateway/launch/' . esc($res['id'])) ?>" target="_blank" class="btn btn-xs btn-primary" title="Launch Remote Session in New Tab">
                    Launch &rarr;
                  </a>
                  <button type="button" class="btn btn-xs btn-ghost" onclick='editResource(<?= json_encode($res, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)' title="Edit Resource">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                  </button>
                  <button type="button" class="btn btn-xs btn-ghost text-danger" onclick="deleteResource('<?= esc($res['id']) ?>', '<?= esc($res['name']) ?>')" title="Delete Resource">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </button>
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
<!-- TAB 2: ACTIVE USER SESSIONS                                            -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="ztna-tab-sessions" class="tab-pane animate-in" style="display:none;">
  <div class="card card-glass" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--border-color); flex-wrap:wrap; gap:12px;">
      <div>
        <h3 style="margin:0; font-size:1rem; font-weight:700; color:var(--text-primary);">Active Clientless Remote Sessions</h3>
        <p style="margin:4px 0 0; font-size:0.78rem; color:var(--text-muted);">Live real-time user connections stream through Guacamole daemon (guacd) and Nginx WebSocket reverse proxy.</p>
      </div>
      <div style="display:flex; align-items:center; gap:8px;">
        <span class="badge badge-indigo"><?= count($sessions) ?> Live Connections</span>
        <button type="button" class="btn btn-xs btn-ghost" onclick="window.location.reload()">Refresh List</button>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table" style="margin:0; width:100%; border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color); background:var(--bg-card-header, rgba(255,255,255,0.02));">
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">User</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Target Resource</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Client IP &amp; Browser</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Started At</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sessions)): ?>
            <tr>
              <td colspan="5" style="padding:36px 18px; text-align:center; color:var(--text-muted); font-size:0.82rem;">
                <div style="font-size:2rem; margin-bottom:8px; opacity:0.6;">⚡</div>
                No clientless sessions currently connected. When users launch sessions from the User Portal, live connections will appear here.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($sessions as $s): ?>
              <tr style="border-bottom:1px solid var(--border-color);" id="sess_row_<?= esc($s['id']) ?>">
                <td style="padding:12px 18px; font-weight:700; color:var(--text-primary);">
                  <div style="display:flex; align-items:center; gap:6px;">
                    <span style="width:8px; height:8px; border-radius:50%; background:var(--color-emerald); display:inline-block;"></span>
                    <?= esc($s['username']) ?>
                  </div>
                </td>
                <td style="padding:12px 18px; font-family:monospace; color:var(--color-cyan);">
                  <?= esc($s['resource_id']) ?>
                </td>
                <td style="padding:12px 18px;">
                  <div style="font-family:monospace; font-size:0.82rem; color:var(--text-primary);"><?= esc($s['client_ip']) ?></div>
                  <div style="font-size:0.72rem; color:var(--text-muted); max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= esc($s['user_agent'] ?? 'HTML5 Browser') ?></div>
                </td>
                <td style="padding:12px 18px; font-size:0.8rem; color:var(--text-secondary);"><?= esc($s['started_at']) ?></td>
                <td style="padding:12px 18px; text-align:right;">
                  <button type="button" class="btn btn-xs btn-danger" onclick="terminateSession('<?= esc($s['id']) ?>')">
                    Terminate Session
                  </button>
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
<!-- TAB 3: COMPANY BRANDING & PORTAL CUSTOMIZATION (HERO FEATURE)          -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="ztna-tab-branding" class="tab-pane animate-in" style="display:none;">
  <div style="display:grid; grid-template-columns:1.15fr 0.85fr; gap:24px; align-items:start;">

    <!-- Left: Branding Controls Form -->
    <div class="card card-glass" style="padding:24px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">
        <div>
          <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-primary);">Enterprise Company Branding</h3>
          <p style="margin:4px 0 0; font-size:0.78rem; color:var(--text-muted);">Personalize the end-user landing portal with corporate logos, brand palette, legal notices, and styling.</p>
        </div>
        <button type="button" class="btn btn-xs btn-ghost text-muted" onclick="resetBrandingDefaults()">Reset Defaults</button>
      </div>

      <form id="ztnaBrandingForm" onsubmit="saveBranding(event)" enctype="multipart/form-data">
        <input type="hidden" name="<?= csrf_token() ?>" value="<?= csrf_hash() ?>" class="csrf-field">
        <input type="hidden" name="enabled" value="<?= esc($settings['enabled'] ?? 1) ?>">
        <input type="hidden" name="external_domain" value="<?= esc($settings['external_domain'] ?? 'gateway.micronos.local') ?>">
        <input type="hidden" name="external_port" value="<?= esc($settings['external_port'] ?? 443) ?>">
        <input type="hidden" name="guacd_host" value="<?= esc($settings['guacd_host'] ?? '127.0.0.1') ?>">
        <input type="hidden" name="guacd_port" value="<?= esc($settings['guacd_port'] ?? 4822) ?>">
        <input type="hidden" name="session_timeout_mins" value="<?= esc($settings['session_timeout_mins'] ?? 60) ?>">
        <input type="hidden" name="require_mfa" value="<?= esc($settings['require_mfa'] ?? 1) ?>">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
          <div>
            <label class="form-label" style="font-weight:600; font-size:0.8rem;">Company / Organization Name</label>
            <input type="text" name="company_name" id="brandCompanyName" class="form-control" value="<?= esc($settings['company_name'] ?? 'Micron Security') ?>" oninput="updateLivePreview()" placeholder="e.g. Acme Corp Inc.">
          </div>
          <div>
            <label class="form-label" style="font-weight:600; font-size:0.8rem;">Portal Window Title</label>
            <input type="text" name="portal_title" id="brandPortalTitle" class="form-control" value="<?= esc($settings['portal_title'] ?? 'Micron OS Clientless Gateway') ?>" oninput="updateLivePreview()" placeholder="e.g. Acme Zero Trust Portal">
          </div>
        </div>

        <!-- Logo & Favicon -->
        <div style="margin-bottom:16px; background:rgba(0,0,0,0.2); padding:14px; border-radius:8px; border:1px solid var(--border-color);">
          <div style="font-weight:700; font-size:0.82rem; color:var(--text-primary); margin-bottom:10px; display:flex; align-items:center; gap:8px;">
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            Company Logo &amp; Favicon
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:10px;">
            <div>
              <label class="form-label" style="font-size:0.75rem; color:var(--text-muted);">Logo Image URL</label>
              <input type="text" name="logo_url" id="brandLogoUrl" class="form-control" value="<?= esc($settings['logo_url'] ?? '') ?>" oninput="updateLivePreview()" placeholder="https://example.com/logo.svg or /img/logo.png">
            </div>
            <div>
              <label class="form-label" style="font-size:0.75rem; color:var(--text-muted);">Or Upload New Logo File</label>
              <input type="file" name="logo_file" id="brandLogoFile" class="form-control" accept="image/png,image/jpeg,image/svg+xml,image/webp">
            </div>
          </div>
          <div style="font-size:0.72rem; color:var(--text-muted);">Supported formats: SVG, PNG, WebP, JPEG. Transparent SVG recommended.</div>
        </div>

        <!-- Color Palette Picker -->
        <div style="margin-bottom:16px;">
          <label class="form-label" style="font-weight:600; font-size:0.8rem; display:flex; justify-content:space-between; align-items:center;">
            <span>Brand Color Palette</span>
            <span style="font-size:0.72rem; font-weight:normal; color:var(--text-muted);">Click a preset or customize HEX</span>
          </label>
          <div style="display:flex; gap:8px; margin-bottom:10px; flex-wrap:wrap;">
            <button type="button" class="btn btn-xs" style="background:#6366f1; color:#fff;" onclick="setBrandPalette('#6366f1', '#4f46e5', '#10b981')">Indigo Classic</button>
            <button type="button" class="btn btn-xs" style="background:#2563eb; color:#fff;" onclick="setBrandPalette('#2563eb', '#1d4ed8', '#06b6d4')">Enterprise Blue</button>
            <button type="button" class="btn btn-xs" style="background:#059669; color:#fff;" onclick="setBrandPalette('#059669', '#047857', '#10b981')">Emerald Teal</button>
            <button type="button" class="btn btn-xs" style="background:#7c3aed; color:#fff;" onclick="setBrandPalette('#7c3aed', '#6d28d9', '#f43f5e')">Cyber Violet</button>
            <button type="button" class="btn btn-xs" style="background:#d97706; color:#fff;" onclick="setBrandPalette('#d97706', '#b45309', '#3b82f6')">Solar Amber</button>
            <button type="button" class="btn btn-xs" style="background:#0f172a; color:#f8fafc; border:1px solid #334155;" onclick="setBrandPalette('#0ea5e9', '#0284c7', '#38bdf8')">Slate Cyan</button>
            <button type="button" class="btn btn-xs" style="background:linear-gradient(135deg, #663399, #9747ff); color:#fff; border-radius:999px; font-weight:600;" onclick="setBrandPalette('#663399', '#4b1478', '#00aa55')">Micron OS</button>
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
            <div>
              <label class="form-label" style="font-size:0.72rem; color:var(--text-muted);">Primary Theme Color</label>
              <div style="display:flex; align-items:center; gap:6px;">
                <input type="color" id="brandColorPicker" value="<?= esc($settings['brand_color'] ?? '#6366f1') ?>" style="width:36px; height:32px; padding:0; border:none; border-radius:4px; cursor:pointer;" oninput="syncColorInput(this.value, 'brandColorText')">
                <input type="text" name="brand_color" id="brandColorText" class="form-control" value="<?= esc($settings['brand_color'] ?? '#6366f1') ?>" oninput="syncColorPicker(this.value, 'brandColorPicker')" style="font-family:monospace; font-size:0.8rem;">
              </div>
            </div>
            <div>
              <label class="form-label" style="font-size:0.72rem; color:var(--text-muted);">Hover State Color</label>
              <div style="display:flex; align-items:center; gap:6px;">
                <input type="color" id="brandHoverPicker" value="<?= esc($settings['brand_color_hover'] ?? '#4f46e5') ?>" style="width:36px; height:32px; padding:0; border:none; border-radius:4px; cursor:pointer;" oninput="syncColorInput(this.value, 'brandHoverText')">
                <input type="text" name="brand_color_hover" id="brandHoverText" class="form-control" value="<?= esc($settings['brand_color_hover'] ?? '#4f46e5') ?>" oninput="syncColorPicker(this.value, 'brandHoverPicker')" style="font-family:monospace; font-size:0.8rem;">
              </div>
            </div>
            <div>
              <label class="form-label" style="font-size:0.72rem; color:var(--text-muted);">Accent Highlight Color</label>
              <div style="display:flex; align-items:center; gap:6px;">
                <input type="color" id="brandAccentPicker" value="<?= esc($settings['brand_accent_color'] ?? '#10b981') ?>" style="width:36px; height:32px; padding:0; border:none; border-radius:4px; cursor:pointer;" oninput="syncColorInput(this.value, 'brandAccentText')">
                <input type="text" name="brand_accent_color" id="brandAccentText" class="form-control" value="<?= esc($settings['brand_accent_color'] ?? '#10b981') ?>" oninput="syncColorPicker(this.value, 'brandAccentPicker')" style="font-family:monospace; font-size:0.8rem;">
              </div>
            </div>
          </div>
        </div>

        <!-- Portal Page Background / Theme -->
        <div style="margin-bottom:16px;">
          <label class="form-label" style="font-weight:600; font-size:0.8rem; display:flex; justify-content:space-between; align-items:center;">
            <span>Portal Page Background / Theme</span>
            <span style="font-size:0.72rem; color:var(--text-muted);">Default user portal backdrop</span>
          </label>
          <input type="hidden" name="portal_theme" id="brandPortalTheme" value="<?= esc($settings['portal_theme'] ?? 'light') ?>">
          <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
            <button type="button" class="btn btn-xs portal-theme-btn <?= ($settings['portal_theme'] ?? 'light') === 'light' ? 'active' : 'btn-ghost' ?>" id="btnThemeLight" onclick="setPortalTheme('light')" style="display:flex; flex-direction:column; align-items:center; gap:4px; padding:10px; border-radius:8px; border:1px solid var(--border-color); text-align:center;">
              <span style="font-size:1.15rem;">☀️</span>
              <span style="font-weight:700; font-size:0.78rem;">Light (Default)</span>
              <span style="font-size:0.67rem; color:var(--text-muted);">Crisp light &amp; soft gray</span>
            </button>
            <button type="button" class="btn btn-xs portal-theme-btn <?= ($settings['portal_theme'] ?? '') === 'gray' ? 'active' : 'btn-ghost' ?>" id="btnThemeGray" onclick="setPortalTheme('gray')" style="display:flex; flex-direction:column; align-items:center; gap:4px; padding:10px; border-radius:8px; border:1px solid var(--border-color); text-align:center;">
              <span style="font-size:1.15rem;">🌫️</span>
              <span style="font-weight:700; font-size:0.78rem;">Slate Gray</span>
              <span style="font-size:0.67rem; color:var(--text-muted);">Neutral slate backdrop</span>
            </button>
            <button type="button" class="btn btn-xs portal-theme-btn <?= ($settings['portal_theme'] ?? '') === 'dark' ? 'active' : 'btn-ghost' ?>" id="btnThemeDark" onclick="setPortalTheme('dark')" style="display:flex; flex-direction:column; align-items:center; gap:4px; padding:10px; border-radius:8px; border:1px solid var(--border-color); text-align:center;">
              <span style="font-size:1.15rem;">🌙</span>
              <span style="font-weight:700; font-size:0.78rem;">Midnight Dark</span>
              <span style="font-size:0.67rem; color:var(--text-muted);">Deep cyber navy</span>
            </button>
          </div>
        </div>

        <!-- Welcome Headlines -->
        <div style="margin-bottom:14px;">
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Portal Welcome Headline</label>
          <input type="text" name="welcome_heading" id="brandWelcomeHeading" class="form-control" value="<?= esc($settings['welcome_heading'] ?? 'Zero-Trust Clientless Access Portal') ?>" oninput="updateLivePreview()" placeholder="e.g. Acme Corporate Workstation Gateway">
        </div>

        <div style="margin-bottom:14px;">
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Portal Subtitle &amp; Guidance</label>
          <textarea name="welcome_subtitle" id="brandWelcomeSubtitle" rows="2" class="form-control" oninput="updateLivePreview()"><?= esc($settings['welcome_subtitle'] ?? 'Secure, zero-install in-browser access to internal desktop workspaces, terminals, and corporate web applications.') ?></textarea>
        </div>

        <!-- Notice Banner -->
        <div style="margin-bottom:14px; background:rgba(0,0,0,0.15); padding:12px; border-radius:8px; border:1px solid var(--border-color);">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <label class="form-label" style="font-weight:600; font-size:0.8rem; margin:0;">Announcement / Legal Notice Banner</label>
            <select name="notice_banner_type" id="brandNoticeType" class="form-select" style="width:130px; font-size:0.75rem;" onchange="updateLivePreview()">
              <option value="info" <?= ($settings['notice_banner_type'] ?? 'info') === 'info' ? 'selected' : '' ?>>Info (Blue)</option>
              <option value="warning" <?= ($settings['notice_banner_type'] ?? '') === 'warning' ? 'selected' : '' ?>>Warning (Amber)</option>
              <option value="danger" <?= ($settings['notice_banner_type'] ?? '') === 'danger' ? 'selected' : '' ?>>Danger (Red)</option>
              <option value="success" <?= ($settings['notice_banner_type'] ?? '') === 'success' ? 'selected' : '' ?>>Success (Green)</option>
            </select>
          </div>
          <input type="text" name="notice_banner" id="brandNoticeText" class="form-control" value="<?= esc($settings['notice_banner'] ?? '') ?>" oninput="updateLivePreview()" placeholder="e.g. Authorized use only. All remote sessions are recorded and audited.">
        </div>

        <!-- Support Link & Footer -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
          <div>
            <label class="form-label" style="font-weight:600; font-size:0.8rem;">Support / Helpdesk URL</label>
            <input type="text" name="help_link" id="brandHelpLink" class="form-control" value="<?= esc($settings['help_link'] ?? '') ?>" placeholder="https://helpdesk.company.com or mailto:support@company.com">
          </div>
          <div>
            <label class="form-label" style="font-weight:600; font-size:0.8rem;">Help Button Label</label>
            <input type="text" name="help_link_text" id="brandHelpText" class="form-control" value="<?= esc($settings['help_link_text'] ?? 'Contact Helpdesk') ?>" oninput="updateLivePreview()">
          </div>
        </div>

        <div style="margin-bottom:14px;">
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Custom Copyright / Compliance Footer</label>
          <input type="text" name="footer_text" id="brandFooterText" class="form-control" value="<?= esc($settings['footer_text'] ?? '© ' . date('Y') . ' Micron OS Zero-Trust Network Access. All rights reserved.') ?>" oninput="updateLivePreview()">
        </div>

        <!-- Custom CSS -->
        <div style="margin-bottom:18px;">
          <label class="form-label" style="font-weight:600; font-size:0.8rem; display:flex; justify-content:space-between;">
            <span>Custom CSS Stylesheet (Optional Override)</span>
            <span style="font-size:0.72rem; color:var(--text-muted);">Scoped to clientless portal &amp; viewer</span>
          </label>
          <textarea name="custom_css" id="brandCustomCss" rows="3" class="form-control" style="font-family:monospace; font-size:0.75rem;" placeholder="/* Add custom styles e.g. .portal-hero { border-radius: 16px; } */" oninput="updateLivePreview()"><?= esc($settings['custom_css'] ?? '') ?></textarea>
        </div>

        <button type="submit" id="btnSaveBranding" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
          Save Company Branding
        </button>
      </form>
    </div>

    <!-- Right: Live Interactive Portal Preview -->
    <div style="position:sticky; top:20px;">
      <div style="margin-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:0.78rem; text-transform:uppercase; font-weight:700; color:var(--text-muted); letter-spacing:0.04em;">
          Live Portal Preview
        </span>
        <div style="display:flex; align-items:center; gap:8px;">
          <!-- Quick Theme Switcher in Preview -->
          <div style="display:inline-flex; background:rgba(0,0,0,0.08); border:1px solid var(--border-color); border-radius:6px; padding:2px; gap:2px;">
            <button type="button" class="btn btn-xs prev-theme-btn <?= ($settings['portal_theme'] ?? 'light') === 'light' ? 'active' : 'btn-ghost' ?>" id="btnPrevThemeLight" onclick="setPortalTheme('light')" title="Preview Light Theme" style="padding:2px 6px; font-size:0.68rem;">☀️ Light</button>
            <button type="button" class="btn btn-xs prev-theme-btn <?= ($settings['portal_theme'] ?? '') === 'gray' ? 'active' : 'btn-ghost' ?>" id="btnPrevThemeGray" onclick="setPortalTheme('gray')" title="Preview Gray Theme" style="padding:2px 6px; font-size:0.68rem;">🌫️ Gray</button>
            <button type="button" class="btn btn-xs prev-theme-btn <?= ($settings['portal_theme'] ?? '') === 'dark' ? 'active' : 'btn-ghost' ?>" id="btnPrevThemeDark" onclick="setPortalTheme('dark')" title="Preview Dark Theme" style="padding:2px 6px; font-size:0.68rem;">🌙 Dark</button>
          </div>

          <!-- Cards / List Mode Switcher -->
          <div style="display:inline-flex; background:rgba(0,0,0,0.08); border:1px solid var(--border-color); border-radius:6px; padding:2px; gap:2px;">
            <button type="button" class="btn btn-xs view-toggle-preview active" id="btnPrevModeCard" onclick="togglePreviewMode('card')" title="Preview Cards" style="padding:2px 8px; font-size:0.68rem;">Cards</button>
            <button type="button" class="btn btn-xs view-toggle-preview btn-ghost" id="btnPrevModeList" onclick="togglePreviewMode('list')" title="Preview List" style="padding:2px 8px; font-size:0.68rem;">List</button>
          </div>
          <span class="badge badge-emerald" style="font-size:0.68rem;">Live</span>
        </div>
      </div>

      <!-- Preview Mock Window Frame (Defaults to Light/Gray) -->
      <div id="prevMockFrame" style="border:1px solid var(--border-color); border-radius:12px; overflow:hidden; background:<?= ($settings['portal_theme'] ?? 'light') === 'dark' ? '#070b14' : (($settings['portal_theme'] ?? 'light') === 'gray' ? '#f1f5f9' : '#f8fafc') ?>; box-shadow:0 12px 36px rgba(0,0,0,0.15); transition:background 0.3s ease;">
        <!-- Mock Browser Chrome -->
        <div id="prevMockChrome" style="background:#f1f5f9; padding:8px 12px; display:flex; align-items:center; gap:8px; border-bottom:1px solid #e2e8f0; transition:background 0.3s ease;">
          <div style="display:flex; gap:5px;">
            <span style="width:9px; height:9px; border-radius:50%; background:#ef4444; display:inline-block;"></span>
            <span style="width:9px; height:9px; border-radius:50%; background:#f59e0b; display:inline-block;"></span>
            <span style="width:9px; height:9px; border-radius:50%; background:#10b981; display:inline-block;"></span>
          </div>
          <div id="prevMockUrlBar" style="flex:1; background:#ffffff; border:1px solid rgba(0,0,0,0.06); border-radius:4px; padding:3px 10px; font-size:0.68rem; color:#334155; font-family:monospace; text-align:center; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
            https://<span id="prevDomainText"><?= esc($settings['external_domain'] ?? 'gateway.micronos.local') ?></span>/portal
          </div>
        </div>

        <!-- Mock Portal Content -->
        <div style="padding:16px; min-height:360px; font-family:sans-serif;">
          <!-- Mock Header -->
          <div id="prevHeaderBorder" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
            <div style="display:flex; align-items:center; gap:8px;">
              <div id="prevLogoBox" style="width:26px; height:26px; border-radius:6px; background:var(--color-indigo); display:flex; align-items:center; justify-content:center; color:#fff; font-size:0.85rem; font-weight:800;">
                M
              </div>
              <span id="prevCompanyName" style="font-weight:700; font-size:0.88rem; color:#0f172a;">
                <?= esc($settings['company_name'] ?? 'Micron Security') ?>
              </span>
            </div>
            <span class="badge" style="background:rgba(16,185,129,0.15); color:#059669; font-size:0.65rem; font-weight:700;">Continuous ZTNA</span>
          </div>

          <!-- Mock Notice Banner -->
          <div id="prevNoticeBanner" style="display:<?= empty($settings['notice_banner']) ? 'none' : 'flex' ?>; align-items:center; gap:8px; padding:8px 12px; border-radius:6px; font-size:0.72rem; margin-bottom:12px; background:rgba(59,130,246,0.15); color:#2563eb; border:1px solid rgba(59,130,246,0.25);">
            <span>📢</span>
            <span id="prevNoticeContent"><?= esc($settings['notice_banner'] ?? '') ?></span>
          </div>

          <!-- Mock Hero Card -->
          <div id="prevHeroCard" style="padding:16px; border-radius:10px; background:#ffffff; border:1px solid #e2e8f0; box-shadow:0 4px 12px rgba(0,0,0,0.04); margin-bottom:14px; transition:all 0.3s ease;">
            <h4 id="prevHeroHeading" style="margin:0 0 6px; font-size:0.95rem; font-weight:800; color:#0f172a;">
              <?= esc($settings['welcome_heading'] ?? 'Zero-Trust Clientless Access Portal') ?>
            </h4>
            <p id="prevHeroSubtitle" style="margin:0; font-size:0.72rem; color:#475569; line-height:1.4;">
              <?= esc($settings['welcome_subtitle'] ?? 'Secure, zero-install in-browser access to internal desktop workspaces, terminals, and corporate web applications.') ?>
            </p>
          </div>

          <!-- Mock Apps Grid / List -->
          <div id="prevAppsSectionTitle" style="font-size:0.72rem; font-weight:700; color:#334155; margin-bottom:8px;">
            Authorized Corporate Applications
          </div>
          <div id="prevAppsGrid" style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:14px;">
            <div class="prev-app-card" style="background:#ffffff; border:1px solid #e2e8f0; box-shadow:0 2px 6px rgba(0,0,0,0.04); border-radius:6px; padding:10px; transition:all 0.3s ease;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; gap:6px;">
                <span style="font-size:0.9rem;">💻</span>
                <span class="badge" style="font-size:0.6rem; color:#2563eb; background:rgba(59,130,246,0.1); padding:1px 4px; border-radius:3px;">RDP</span>
              </div>
              <div class="prev-app-title" style="font-weight:700; font-size:0.75rem; color:#0f172a;">Windows Bastion</div>
              <div style="font-size:0.65rem; color:#64748b; margin-top:2px;">192.168.1.50:3389</div>
              <button type="button" id="prevLaunchBtn1" class="btn btn-xs" style="width:100%; margin-top:6px; background:#6366f1; color:#fff; font-size:0.65rem; padding:3px 6px; font-weight:600; border-radius:4px;">Launch &rarr;</button>
            </div>

            <div class="prev-app-card" style="background:#ffffff; border:1px solid #e2e8f0; box-shadow:0 2px 6px rgba(0,0,0,0.04); border-radius:6px; padding:10px; transition:all 0.3s ease;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; gap:6px;">
                <span style="font-size:0.9rem;">⌨️</span>
                <span class="badge" style="font-size:0.6rem; color:#059669; background:rgba(16,185,129,0.1); padding:1px 4px; border-radius:3px;">SSH</span>
              </div>
              <div class="prev-app-title" style="font-weight:700; font-size:0.75rem; color:#0f172a;">Linux Production Terminal</div>
              <div style="font-size:0.65rem; color:#64748b; margin-top:2px;">192.168.1.100:22</div>
              <button type="button" id="prevLaunchBtn2" class="btn btn-xs" style="width:100%; margin-top:6px; background:#6366f1; color:#fff; font-size:0.65rem; padding:3px 6px; font-weight:600; border-radius:4px;">Launch &rarr;</button>
            </div>
          </div>

          <!-- Mock Footer -->
          <div id="prevFooterBorder" style="border-top:1px solid #e2e8f0; padding-top:8px; display:flex; justify-content:space-between; align-items:center; font-size:0.65rem; color:#64748b;">
            <span id="prevFooterContent"><?= esc($settings['footer_text'] ?? '© 2026 Micron OS Zero-Trust Network Access.') ?></span>
            <span id="prevHelpButton" style="color:var(--color-indigo); font-weight:600; text-decoration:underline;">
              <?= esc($settings['help_link_text'] ?? 'Contact Helpdesk') ?>
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 4: GATEWAY CONFIGURATION & guacd DAEMON (CORE SETTINGS)             -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="ztna-tab-settings" class="tab-pane animate-in" style="display:none;">
  <div style="display:grid; grid-template-columns:1.1fr 0.9fr; gap:24px; align-items:start;">

    <!-- Left: Core Settings Form -->
    <div class="card card-glass" style="padding:24px;">
      <h3 style="margin:0 0 16px; font-size:1.1rem; font-weight:700; color:var(--text-primary);">Clientless Gateway &amp; guacd Daemon Settings</h3>
      <form id="ztnaCoreSettingsForm" onsubmit="saveCoreSettings(event)">
        <input type="hidden" name="<?= csrf_token() ?>" value="<?= csrf_hash() ?>" class="csrf-field">
        <input type="hidden" name="company_name" value="<?= esc($settings['company_name'] ?? 'Micron Security') ?>">
        <input type="hidden" name="portal_title" value="<?= esc($settings['portal_title'] ?? 'Micron OS Clientless Gateway') ?>">
        <input type="hidden" name="logo_url" value="<?= esc($settings['logo_url'] ?? '') ?>">
        <input type="hidden" name="favicon_url" value="<?= esc($settings['favicon_url'] ?? '') ?>">
        <input type="hidden" name="brand_color" value="<?= esc($settings['brand_color'] ?? '#6366f1') ?>">
        <input type="hidden" name="brand_color_hover" value="<?= esc($settings['brand_color_hover'] ?? '#4f46e5') ?>">
        <input type="hidden" name="brand_accent_color" value="<?= esc($settings['brand_accent_color'] ?? '#10b981') ?>">
        <input type="hidden" name="welcome_heading" value="<?= esc($settings['welcome_heading'] ?? 'Zero-Trust Clientless Access Portal') ?>">
        <input type="hidden" name="welcome_subtitle" value="<?= esc($settings['welcome_subtitle'] ?? '') ?>">
        <input type="hidden" name="notice_banner" value="<?= esc($settings['notice_banner'] ?? '') ?>">
        <input type="hidden" name="notice_banner_type" value="<?= esc($settings['notice_banner_type'] ?? 'info') ?>">
        <input type="hidden" name="help_link" value="<?= esc($settings['help_link'] ?? '') ?>">
        <input type="hidden" name="help_link_text" value="<?= esc($settings['help_link_text'] ?? 'Contact Helpdesk') ?>">
        <input type="hidden" name="footer_text" value="<?= esc($settings['footer_text'] ?? '') ?>">
        <input type="hidden" name="custom_css" value="<?= esc($settings['custom_css'] ?? '') ?>">
        <input type="hidden" name="portal_theme" value="<?= esc($settings['portal_theme'] ?? 'light') ?>">

        <div style="margin-bottom:18px; background:rgba(0,0,0,0.15); padding:14px; border-radius:8px; border:1px solid var(--border-color);">
          <label style="display:flex; align-items:flex-start; gap:12px; cursor:pointer;">
            <input type="checkbox" name="enabled" value="1" <?= ($settings['enabled'] ?? 1) ? 'checked' : '' ?> style="width:18px; height:18px; margin-top:2px;">
            <div>
              <div style="font-weight:700; font-size:0.9rem; color:var(--text-primary);">Enable In-Browser Clientless Gateway Core</div>
              <div style="font-size:0.78rem; color:var(--text-muted); margin-top:2px;">Enables Nginx reverse proxy subrequest authentication, Guacamole WebSocket bridge, and opens firewall rules.</div>
            </div>
          </label>
        </div>

        <div style="display:grid; grid-template-columns:2fr 1fr; gap:14px; margin-bottom:14px;">
          <div>
            <label class="form-label" style="font-weight:600; font-size:0.8rem;">External Gateway FQDN / Hostname</label>
            <input type="text" name="external_domain" class="form-control" value="<?= esc($settings['external_domain'] ?? 'gateway.micronos.local') ?>" placeholder="gateway.yourdomain.com">
          </div>
          <div>
            <label class="form-label" style="font-weight:600; font-size:0.8rem;">External Port</label>
            <input type="number" name="external_port" class="form-control" value="<?= esc($settings['external_port'] ?? 443) ?>" min="1" max="65535">
          </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
          <div>
            <label class="form-label" style="font-weight:600; font-size:0.8rem;">guacd Daemon Host</label>
            <input type="text" name="guacd_host" id="guacdHostInput" class="form-control" value="<?= esc($settings['guacd_host'] ?? '127.0.0.1') ?>">
          </div>
          <div>
            <label class="form-label" style="font-weight:600; font-size:0.8rem;">guacd Daemon Port</label>
            <input type="number" name="guacd_port" id="guacdPortInput" class="form-control" value="<?= esc($settings['guacd_port'] ?? 4822) ?>">
          </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:18px;">
          <div>
            <label class="form-label" style="font-weight:600; font-size:0.8rem;">Session Timeout (Minutes)</label>
            <input type="number" name="session_timeout_mins" class="form-control" value="<?= esc($settings['session_timeout_mins'] ?? 60) ?>" min="5" max="480">
          </div>
          <div style="display:flex; align-items:center; padding-top:20px;">
            <label style="display:flex; align-items:center; gap:8px; font-size:0.82rem; cursor:pointer;">
              <input type="checkbox" name="require_mfa" value="1" <?= ($settings['require_mfa'] ?? 1) ? 'checked' : '' ?> style="width:16px; height:16px;">
              Mandate TOTP Multi-Factor Authentication
            </label>
          </div>
        </div>

        <?php $accessMode = ($settings['access_mode'] ?? 'open') === 'firewall' ? 'firewall' : 'open'; ?>
        <div style="margin-bottom:18px;">
          <label class="form-label" style="font-weight:600; font-size:0.8rem; display:inline-flex; align-items:center; gap:6px;">
            Gateway Access (port <?= (int) ($settings['external_port'] ?? 443) ?>)
            <button type="button" onclick="openGwAccessHelp()" title="How to control gateway access with firewall rules" aria-label="Help: controlling gateway access with firewall rules"
                    style="display:inline-flex; align-items:center; gap:5px; height:24px; border-radius:12px; border:1px solid #6366f1; background:#6366f1; color:#ffffff; cursor:pointer; padding:0 10px 0 6px; font-size:0.75rem; font-weight:600;">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01"/></svg>
              How it works
            </button>
          </label>
          <div style="display:grid; gap:8px;">
            <label style="display:flex; gap:10px; align-items:flex-start; cursor:pointer; padding:10px 12px; border:1px solid var(--color-border-bright); border-radius:8px;">
              <input type="radio" name="access_mode" value="open" <?= $accessMode === 'open' ? 'checked' : '' ?> style="margin-top:3px;">
              <span>
                <strong style="font-size:0.85rem; color:var(--text-primary);">Open</strong>
                <span style="display:block; font-size:0.76rem; color:var(--text-muted);">Anyone can reach the gateway login page; the login and two-step verification are the only gate.</span>
              </span>
            </label>
            <label style="display:flex; gap:10px; align-items:flex-start; cursor:pointer; padding:10px 12px; border:1px solid var(--color-border-bright); border-radius:8px;">
              <input type="radio" name="access_mode" value="firewall" <?= $accessMode === 'firewall' ? 'checked' : '' ?> style="margin-top:3px;">
              <span>
                <strong style="font-size:0.85rem; color:var(--text-primary);">Controlled by firewall rules</strong>
                <span style="display:block; font-size:0.76rem; color:var(--text-muted); line-height:1.5;">
                  Only sources allowed by a policy in <a href="<?= base_url('firewall/rules') ?>">Firewall → Rules</a> with Outgoing Interface <em>This Firewall</em> and service <code><?= esc(\App\Libraries\GatewayAccessPolicy::SERVICE_NAME) ?></code> reach the gateway; everything else is dropped and logged (MICRON-ZTNA-DENY).
                  Switching on adds an "allow all, log" policy so nothing breaks; add narrower policies above it, then change it to Deny.
                </span>
              </span>
            </label>
          </div>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button type="submit" class="btn btn-primary">Save Daemon Settings</button>
          <button type="button" class="btn btn-ghost" onclick="testGuacdConnection()" style="display:inline-flex; align-items:center; gap:6px;">
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Test guacd Connection
          </button>
        </div>
      </form>
    </div>

    <!-- Right: Daemon Runtime Diagnostics & Status -->
    <div class="card card-glass" style="padding:24px;">
      <h3 style="margin:0 0 16px; font-size:1.1rem; font-weight:700; color:var(--text-primary);">Service Diagnostics &amp; Telemetry</h3>

      <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:20px;">
        <!-- guacd status -->
        <div style="background:rgba(0,0,0,0.2); padding:12px 16px; border-radius:8px; border:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
          <div>
            <div style="font-weight:700; font-size:0.85rem; color:var(--text-primary);">Guacamole Proxy Daemon (guacd)</div>
            <div style="font-size:0.75rem; color:var(--text-muted); font-family:monospace;"><?= esc($settings['guacd_host'] ?? '127.0.0.1') ?>:<?= esc($settings['guacd_port'] ?? 4822) ?></div>
          </div>
          <span id="diagGuacdStatus" class="badge badge-emerald">ACTIVE / LISTENING</span>
        </div>

        <!-- Nginx status -->
        <div style="background:rgba(0,0,0,0.2); padding:12px 16px; border-radius:8px; border:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
          <div>
            <div style="font-weight:700; font-size:0.85rem; color:var(--text-primary);">Nginx Reverse Proxy &amp; SSL Engine</div>
            <div style="font-size:0.75rem; color:var(--text-muted); font-family:monospace;">Port <?= esc($settings['external_port'] ?? 443) ?> &bull; TLS 1.3 / HTTP2</div>
          </div>
          <span id="diagNginxStatus" class="badge badge-emerald">ACTIVE / PROXYING</span>
        </div>

        <!-- Firewall nftables status -->
        <div style="background:rgba(0,0,0,0.2); padding:12px 16px; border-radius:8px; border:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
          <div>
            <div style="font-weight:700; font-size:0.85rem; color:var(--text-primary);">Linux nftables Input Policy</div>
            <div style="font-size:0.75rem; color:var(--text-muted);">Port <?= esc($settings['external_port'] ?? 443) ?> ACCEPT inbound on WAN &amp; LAN</div>
          </div>
          <span class="badge badge-emerald">PERMITTED</span>
        </div>

        <!-- TLS Cert -->
        <div style="background:rgba(0,0,0,0.2); padding:12px 16px; border-radius:8px; border:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
          <div>
            <div style="font-weight:700; font-size:0.85rem; color:var(--text-primary);">Gateway TLS Certificate</div>
            <div style="font-size:0.75rem; color:var(--text-muted);"><?= esc($certStatus['name']) ?></div>
          </div>
          <?php if ($certStatus['self_signed']): ?>
            <span class="badge badge-warning">SELF-SIGNED</span>
          <?php elseif ($certStatus['days_left'] !== null && $certStatus['days_left'] < 0): ?>
            <span class="badge badge-danger">EXPIRED</span>
          <?php else: ?>
            <span class="badge badge-success"><?= $certStatus['source'] === 'acme' ? "LET'S ENCRYPT" : 'INSTALLED' ?></span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Test Output Box -->
      <div id="guacdTestResultBox" style="display:none; padding:12px 16px; border-radius:8px; font-size:0.82rem; margin-bottom:16px;">
      </div>

      <div style="font-size:0.75rem; color:var(--text-muted); line-height:1.5;">
        <strong>Post-Installation Note:</strong> The Clientless Gateway reverse proxy configuration is re-evaluated and reloaded whenever you click <em>Apply &amp; Reload Proxy</em>. Port <?= esc($settings['external_port'] ?? 443) ?> is automatically managed in Linux nftables.
      </div>
    </div>
  </div>

  <!-- Gateway HTTPS certificate -->
  <?php
    $cs        = $certStatus;
    $gwDomain  = (string) ($settings['external_domain'] ?? '');
    $srcLabel  = ['acme' => "Let's Encrypt", 'imported' => 'Uploaded', 'generated' => 'Internal CA', 'csr' => 'CSR-signed', 'self-signed' => 'Self-signed'][$cs['source']] ?? ucfirst($cs['source']);
  ?>
  <div class="card card-glass" style="padding:24px; margin-top:24px;" id="gatewayCertCard">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
      <div>
        <h3 style="margin:0 0 4px; font-size:1.1rem; font-weight:700; color:var(--text-primary);">Gateway HTTPS Certificate</h3>
        <div style="font-size:0.8rem; color:var(--text-muted);">The certificate users see when they open https://<?= esc($gwDomain) ?><?= (int) ($settings['external_port'] ?? 443) !== 443 ? ':' . (int) $settings['external_port'] : '' ?></div>
      </div>
      <div style="text-align:right; font-size:0.8rem; color:var(--text-muted); line-height:1.6;">
        <div><strong style="color:var(--text-primary);"><?= esc($cs['name']) ?></strong> <span class="badge <?= $cs['self_signed'] ? 'badge-warning' : 'badge-info' ?>" style="margin-left:6px;"><?= esc($srcLabel) ?></span></div>
        <?php if (! $cs['self_signed']): ?>
          <div>Issued to <?= esc($cs['common_name']) ?><?= $cs['issuer'] !== '' ? ' by ' . esc($cs['issuer']) : '' ?></div>
          <div>Expires <?= esc((string) $cs['valid_until']) ?><?php if ($cs['days_left'] !== null): ?> (<?= $cs['days_left'] < 0 ? 'expired' : (int) $cs['days_left'] . ' days left' ?>)<?php endif; ?></div>
          <?php if (! $cs['covers_domain']): ?>
            <div style="color:var(--color-amber);">Does not list <?= esc($gwDomain) ?>; browsers will warn.</div>
          <?php endif; ?>
        <?php else: ?>
          <div style="color:var(--color-amber);">Browsers will show a security warning until you install a trusted certificate.</div>
        <?php endif; ?>
      </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:18px;">

      <!-- Let's Encrypt -->
      <form id="gwAcmeForm" onsubmit="gwCertSubmit(event, 'acme')" style="background:rgba(0,0,0,0.12); border:1px solid var(--color-border-bright); border-radius:10px; padding:16px;">
        <input type="hidden" name="<?= csrf_token() ?>" value="<?= csrf_hash() ?>" class="csrf-field">
        <div style="font-weight:700; font-size:0.9rem; color:var(--text-primary); margin-bottom:4px;">Let's Encrypt (automatic)</div>
        <p style="font-size:0.76rem; color:var(--text-muted); margin:0 0 12px; line-height:1.5;">
          Free trusted certificate for <strong><?= esc($gwDomain) ?></strong>, renewed automatically about 30 days before expiry.
          <?= esc($gwDomain) ?> must resolve to this appliance's public address and TCP port 80 must reach it from the internet. While this is on, WAN port 80 is answered by the gateway (validation and a redirect to HTTPS); the management UI is not exposed.
        </p>
        <label class="form-label" style="font-weight:600; font-size:0.8rem;" for="gwAcmeEmail">Contact email (expiry notices)</label>
        <input type="email" id="gwAcmeEmail" name="acme_email" class="form-control" required value="<?= esc($settings['acme_email'] ?? '') ?>" placeholder="it-admin@yourdomain.com" style="margin-bottom:10px;">
        <label style="display:flex; align-items:center; gap:8px; font-size:0.78rem; cursor:pointer; margin-bottom:12px;">
          <input type="checkbox" name="acme_staging" value="1" <?= (int) ($settings['acme_staging'] ?? 0) === 1 ? 'checked' : '' ?>>
          Use the Let's Encrypt staging server (for testing; not trusted by browsers)
        </label>
        <?php if (! empty($settings['acme_last_error'])): ?>
          <div style="font-size:0.75rem; color:var(--color-rose); margin-bottom:10px; line-height:1.45;">Last attempt failed: <?= esc($settings['acme_last_error']) ?></div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" style="width:100%;"><?= $cs['source'] === 'acme' ? 'Re-issue Certificate Now' : 'Issue Certificate' ?></button>
      </form>

      <!-- Upload -->
      <form id="gwUploadForm" onsubmit="gwCertSubmit(event, 'upload')" enctype="multipart/form-data" style="background:rgba(0,0,0,0.12); border:1px solid var(--color-border-bright); border-radius:10px; padding:16px;">
        <input type="hidden" name="<?= csrf_token() ?>" value="<?= csrf_hash() ?>" class="csrf-field">
        <div style="font-weight:700; font-size:0.9rem; color:var(--text-primary); margin-bottom:4px;">Upload a certificate</div>
        <p style="font-size:0.76rem; color:var(--text-muted); margin:0 0 12px; line-height:1.5;">From a commercial or internal CA. PEM files (.crt / .pem / .key). A full-chain file can go in the certificate field. Uploaded certificates must be renewed by you.</p>
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Certificate</label>
        <input type="file" name="cert_file" accept=".pem,.crt,.cer,.txt" class="form-control" style="margin-bottom:6px;">
        <textarea name="cert_pem" class="form-control" rows="2" placeholder="…or paste -----BEGIN CERTIFICATE-----" style="font-family:monospace; font-size:0.72rem; margin-bottom:10px;"></textarea>
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Private key</label>
        <input type="file" name="key_file" accept=".pem,.key,.txt" class="form-control" style="margin-bottom:6px;">
        <textarea name="key_pem" class="form-control" rows="2" placeholder="…or paste -----BEGIN PRIVATE KEY-----" style="font-family:monospace; font-size:0.72rem; margin-bottom:10px;" autocomplete="off"></textarea>
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Intermediate chain (optional)</label>
        <input type="file" name="chain_file" accept=".pem,.crt,.cer,.txt" class="form-control" style="margin-bottom:12px;">
        <button type="submit" class="btn btn-primary" style="width:100%;">Upload and Use</button>
      </form>

      <!-- Existing certificate -->
      <form id="gwSelectForm" onsubmit="gwCertSubmit(event, 'select')" style="background:rgba(0,0,0,0.12); border:1px solid var(--color-border-bright); border-radius:10px; padding:16px;">
        <input type="hidden" name="<?= csrf_token() ?>" value="<?= csrf_hash() ?>" class="csrf-field">
        <div style="font-weight:700; font-size:0.9rem; color:var(--text-primary); margin-bottom:4px;">Use an existing certificate</div>
        <p style="font-size:0.76rem; color:var(--text-muted); margin:0 0 12px; line-height:1.5;">Any server certificate with a private key from <a href="<?= base_url('certificates') ?>">Certificates</a>, or go back to the self-signed one.</p>
        <select name="cert_id" class="form-control form-select" style="margin-bottom:12px;">
          <option value="0" <?= $cs['cert_id'] === 0 ? 'selected' : '' ?>>Self-signed (generated by the appliance)</option>
          <?php foreach ($certCandidates as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $cs['cert_id'] === (int) $c['id'] ? 'selected' : '' ?>>
              <?= esc($c['name']) ?> — <?= esc($c['common_name']) ?><?= ! empty($c['valid_until']) ? ' (until ' . esc(substr((string) $c['valid_until'], 0, 10)) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-ghost" style="width:100%;">Use Selected Certificate</button>
      </form>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- HELP: GATEWAY ACCESS CONTROLLED BY FIREWALL RULES                        -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<?php $gwPort = (int) ($settings['external_port'] ?? 443); $gwSvc = \App\Libraries\GatewayAccessPolicy::SERVICE_NAME; ?>
<div id="gwAccessHelp" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="gwAccessHelpTitle" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(6px); z-index:1060; align-items:center; justify-content:center;">
  <div class="card card-glass animate-in gw-help" style="width:95%; max-width:760px; max-height:90vh; overflow-y:auto; padding:24px; box-shadow:0 16px 40px rgba(0,0,0,0.6); border:1px solid var(--color-border-bright);">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:14px; border-bottom:1px solid var(--color-border-bright); padding-bottom:12px;">
      <div>
        <h3 id="gwAccessHelpTitle" style="margin:0; font-size:1.15rem; font-weight:700; color:var(--text-primary);">Controlling gateway access with firewall rules</h3>
        <p style="margin:2px 0 0; font-size:0.78rem; color:var(--text-muted);">Decide which networks can even reach the gateway login page on port <?= $gwPort ?>.</p>
      </div>
      <button type="button" class="btn btn-xs btn-ghost" onclick="closeGwAccessHelp()" aria-label="Close" style="font-size:1.2rem; line-height:1;">&times;</button>
    </div>

    <h4>The two modes</h4>
    <ul>
      <li><strong>Open</strong>: anyone on the internet can reach the login page. The username, password and two-step verification are the only protection.</li>
      <li><strong>Controlled by firewall rules</strong>: a connection to port <?= $gwPort ?> is checked against your firewall policies first. Sources no policy allows are dropped before they ever see the login page, and every drop is logged.</li>
    </ul>

    <h4>Which policies count</h4>
    <p>A policy in <a href="<?= base_url('firewall/rules') ?>">Firewall → Rules</a> applies to the gateway when <strong>all</strong> of these are true:</p>
    <table class="gw-help-table">
      <tr><th>Outgoing Interface</th><td><strong>This Firewall</strong> (the appliance itself, not a network behind it)</td></tr>
      <tr><th>Service</th><td><code><?= esc($gwSvc) ?></code> (TCP <?= $gwPort ?>), or any service or group that includes TCP <?= $gwPort ?>, or <code>ALL</code></td></tr>
      <tr><th>Status</th><td>Enabled, and its schedule (if any) is active right now</td></tr>
    </table>
    <p>For these policies the firewall uses the <strong>Incoming Interface</strong>, <strong>Sources</strong>, <strong>Schedule</strong>, <strong>Action</strong> and <strong>Log</strong> settings. Destination addresses are ignored, because the destination is always the gateway itself. Policies are checked <strong>top to bottom</strong>, and the first match wins. If nothing matches, the connection is dropped and logged as <code>MICRON-ZTNA-DENY</code>.</p>

    <h4>Rolling it out gradually</h4>
    <ol>
      <li><strong>Switch on.</strong> Choose <em>Controlled by firewall rules</em> and save. The appliance creates the <code><?= esc($gwSvc) ?></code> service and the policy <em><?= esc(\App\Libraries\GatewayAccessPolicy::STARTER_NAME) ?></em>, which allows every source and logs it. Nothing changes for your users yet.</li>
      <li><strong>Observe.</strong> For a few days, look at the starter policy's hits and the firewall log to see where real users connect from: offices, home ISPs, mobile networks, countries.</li>
      <li><strong>Add allow policies above the starter policy</strong>, one for each group you trust:
        <ul>
          <li>Head office or branch public IPs: an address object or group</li>
          <li>Partners or vendors: their published ranges, ideally with a schedule</li>
          <li>Remote staff: a GeoIP country object (for example, India only)</li>
          <li>Support hours only: add a schedule to the policy</li>
        </ul>
      </li>
      <li><strong>Check.</strong> Once your users' traffic is matching the new policies, the starter policy should see almost no hits.</li>
      <li><strong>Lock down.</strong> Edit the starter policy and change its action to <strong>Deny</strong> (keep logging on), or disable it. From then on, only the sources you listed reach the login page.</li>
    </ol>

    <h4>Example policy set</h4>
    <table class="gw-help-table">
      <tr><th>#</th><th>Name</th><th>Incoming</th><th>Sources</th><th>Outgoing</th><th>Service</th><th>Action</th></tr>
      <tr><td>1</td><td>ZTNA – Head office</td><td>WAN</td><td>HO-Public-IPs</td><td>This Firewall</td><td><?= esc($gwSvc) ?></td><td>Allow</td></tr>
      <tr><td>2</td><td>ZTNA – Branches</td><td>WAN</td><td>Branch-IP-Group</td><td>This Firewall</td><td><?= esc($gwSvc) ?></td><td>Allow</td></tr>
      <tr><td>3</td><td>ZTNA – Staff in India (office hours)</td><td>WAN</td><td>GeoIP: IN</td><td>This Firewall</td><td><?= esc($gwSvc) ?></td><td>Allow + schedule</td></tr>
      <tr><td>4</td><td>Block known-bad sources</td><td>WAN</td><td>Blocklist group</td><td>This Firewall</td><td><?= esc($gwSvc) ?></td><td>Deny + log</td></tr>
      <tr><td>5</td><td><?= esc(\App\Libraries\GatewayAccessPolicy::STARTER_NAME) ?></td><td>any</td><td>all</td><td>This Firewall</td><td><?= esc($gwSvc) ?></td><td>Allow + log → later Deny</td></tr>
    </table>
    <p style="font-size:0.76rem;">Put a deny for known-bad sources <em>above</em> any broad allow it should override, because the first match wins.</p>

    <h4>Good to know</h4>
    <ul>
      <li>The <strong>management UI is never affected</strong>. These policies only govern the gateway port.</li>
      <li>If your admin-access settings already allow port <?= $gwPort ?> from a LAN, LAN users reach the gateway before these policies are checked. Restrict admin access if LAN users must go through them too.</li>
      <li>Let's Encrypt validation on WAN port 80 is handled separately and keeps working.</li>
      <li>If you change the gateway's External Port, the <code><?= esc($gwSvc) ?></code> service follows it automatically.</li>
      <li>Policies with Outgoing Interface <em>This Firewall</em> only control traffic to the appliance. They never open forwarding to your internal networks.</li>
      <li>Switching back to <strong>Open</strong> restores the old behaviour. Your policies are kept, so you can switch back on later.</li>
      <li>To see who was blocked, search the firewall log for <code>MICRON-ZTNA-DENY</code>.</li>
    </ul>

    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:18px;">
      <a class="btn btn-ghost" href="<?= base_url('firewall/rules') ?>">Open Firewall → Rules</a>
      <button type="button" class="btn btn-primary" onclick="closeGwAccessHelp()">Got it</button>
    </div>
  </div>
</div>
<style>
.gw-help h4 { margin: 18px 0 6px; font-size: 0.92rem; font-weight: 700; color: var(--text-primary); }
.gw-help p, .gw-help li { font-size: 0.82rem; line-height: 1.6; color: var(--text-secondary); }
.gw-help ul, .gw-help ol { margin: 0 0 6px; padding-left: 20px; }
.gw-help li { margin-bottom: 4px; }
.gw-help code { font-size: 0.78rem; padding: 1px 5px; border-radius: 4px; background: rgba(127,127,127,0.15); }
.gw-help-table { width: 100%; border-collapse: collapse; font-size: 0.78rem; margin: 6px 0 8px; }
.gw-help-table th, .gw-help-table td { border: 1px solid var(--color-border-bright); padding: 6px 8px; text-align: left; vertical-align: top; color: var(--text-secondary); }
.gw-help-table th { color: var(--text-primary); font-weight: 600; background: rgba(127,127,127,0.08); }
@media (max-width: 640px) { .gw-help-table { display: block; overflow-x: auto; } }
</style>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: PUBLISH / EDIT RESOURCE TARGET                                  -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="resourceModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(6px); z-index:1050; align-items:center; justify-content:center;">
  <div class="card card-glass animate-in" style="width:95%; max-width:640px; max-height:90vh; overflow-y:auto; padding:24px; box-shadow:0 16px 40px rgba(0,0,0,0.6); border:1px solid var(--color-border-bright);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">
      <div>
        <h3 style="margin:0; font-size:1.15rem; font-weight:700; color:var(--text-primary);" id="resourceModalTitle">Publish Internal Resource Target</h3>
        <p style="margin:2px 0 0; font-size:0.75rem; color:var(--text-muted);">Configure zero-install browser streaming for Windows RDP, Linux Shell, VNC, or Web App.</p>
      </div>
      <button type="button" class="btn btn-xs btn-ghost" onclick="closeResourceModal()" style="font-size:1.2rem; line-height:1;">&times;</button>
    </div>

    <form id="resourceForm" onsubmit="saveResource(event)">
      <input type="hidden" name="<?= csrf_token() ?>" value="<?= csrf_hash() ?>" class="csrf-field">
      <input type="hidden" name="id" id="resId" value="">

      <!-- Protocol Type Selection Cards -->
      <div style="margin-bottom:16px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem; margin-bottom:8px; display:block;">Protocol &amp; Workstation Type</label>
        <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:8px;">
          <label style="cursor:pointer;">
            <input type="radio" name="type" value="web" id="proto_web" style="display:none;" onchange="onProtocolChange('web')" checked>
            <div class="proto-card active" id="protoCard_web" style="border:1px solid var(--border-color); border-radius:8px; padding:10px 8px; text-align:center; transition:all 0.2s;">
              <div style="font-size:1.3rem;">🌐</div>
              <div style="font-size:0.75rem; font-weight:700; margin-top:4px;">Web App</div>
              <div style="font-size:0.65rem; color:var(--text-muted);">HTTP/HTTPS</div>
            </div>
          </label>

          <label style="cursor:pointer;">
            <input type="radio" name="type" value="rdp" id="proto_rdp" style="display:none;" onchange="onProtocolChange('rdp')">
            <div class="proto-card" id="protoCard_rdp" style="border:1px solid var(--border-color); border-radius:8px; padding:10px 8px; text-align:center; transition:all 0.2s;">
              <div style="font-size:1.3rem;">💻</div>
              <div style="font-size:0.75rem; font-weight:700; margin-top:4px;">Windows RDP</div>
              <div style="font-size:0.65rem; color:var(--text-muted);">Desktop 3389</div>
            </div>
          </label>

          <label style="cursor:pointer;">
            <input type="radio" name="type" value="ssh" id="proto_ssh" style="display:none;" onchange="onProtocolChange('ssh')">
            <div class="proto-card" id="protoCard_ssh" style="border:1px solid var(--border-color); border-radius:8px; padding:10px 8px; text-align:center; transition:all 0.2s;">
              <div style="font-size:1.3rem;">⌨️</div>
              <div style="font-size:0.75rem; font-weight:700; margin-top:4px;">Linux Shell</div>
              <div style="font-size:0.65rem; color:var(--text-muted);">SSH 22</div>
            </div>
          </label>

          <label style="cursor:pointer;">
            <input type="radio" name="type" value="vnc" id="proto_vnc" style="display:none;" onchange="onProtocolChange('vnc')">
            <div class="proto-card" id="protoCard_vnc" style="border:1px solid var(--border-color); border-radius:8px; padding:10px 8px; text-align:center; transition:all 0.2s;">
              <div style="font-size:1.3rem;">🖥️</div>
              <div style="font-size:0.75rem; font-weight:700; margin-top:4px;">Console VNC</div>
              <div style="font-size:0.65rem; color:var(--text-muted);">VNC 5900</div>
            </div>
          </label>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1.4fr 1fr; gap:12px; margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Resource Target Name</label>
          <input type="text" name="name" id="resName" class="form-control" required placeholder="e.g. Production Active Directory Bastion" oninput="autoGenerateSlug()">
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Portal URL Slug</label>
          <input type="text" name="slug" id="resSlug" class="form-control" placeholder="auto-slug">
        </div>
      </div>

      <div style="display:grid; grid-template-columns:2fr 1fr; gap:12px; margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Internal Hostname / IP Address</label>
          <input type="text" name="internal_host" id="resHost" class="form-control" required placeholder="192.168.10.50 or srv.internal.lan">
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Internal Port</label>
          <input type="number" name="internal_port" id="resPort" class="form-control" value="80" required min="1" max="65535">
        </div>
      </div>

      <div style="margin-bottom:12px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Description &amp; Department Scope</label>
        <input type="text" name="description" id="resDesc" class="form-control" placeholder="e.g. Finance team Windows Server 2022 accounting workstation">
      </div>

      <!-- Allowed Roles RBAC -->
      <div style="margin-bottom:14px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
          <label class="form-label" style="font-weight:600; font-size:0.8rem; margin:0;">Allowed RBAC User Roles</label>
          <div style="display:flex; gap:8px;">
            <button type="button" class="btn btn-xs btn-ghost text-muted" onclick="selectAllRoles(true)">Select All</button>
            <button type="button" class="btn btn-xs btn-ghost text-muted" onclick="selectAllRoles(false)">Clear</button>
          </div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; background:rgba(0,0,0,0.25); padding:12px; border-radius:8px; border:1px solid var(--border-color); max-height:130px; overflow-y:auto;">
          <?php foreach ($allRoles as $role): ?>
            <label style="font-size:0.75rem; display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="allowed_roles[]" value="<?= esc($role['name']) ?>" id="role_<?= esc($role['id']) ?>">
              <span><?= esc($role['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="display:flex; justify-content:space-between; align-items:center; padding-top:14px; border-top:1px solid var(--border-color);">
        <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; cursor:pointer;">
          <input type="checkbox" name="require_auth" value="1" id="resRequireAuth" checked>
          Require Identity Authentication
        </label>
        <div style="display:flex; gap:10px;">
          <button type="button" class="btn btn-ghost" onclick="closeResourceModal()">Cancel</button>
          <button type="submit" id="btnSaveResource" class="btn btn-primary">Save &amp; Publish</button>
        </div>
      </div>
    </form>
  </div>
</div>

<style>
.proto-card.active {
  border-color: var(--color-indigo) !important;
  background: rgba(99,102,241,0.15) !important;
  box-shadow: 0 0 12px rgba(99,102,241,0.3);
}
</style>

<script>
// ── CSRF Synchronizer ──
function updateCsrf(token, hash) {
  if (!hash) return;
  document.querySelectorAll('.csrf-field').forEach(input => {
    input.value = hash;
  });
}

// ── Tab Switcher ──
function switchZtnaTab(tab, el) {
  document.querySelectorAll('.tab-pane').forEach(p => p.style.display = 'none');
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  const target = document.getElementById('ztna-tab-' + tab);
  if (target) target.style.display = 'block';
  if (el) el.classList.add('active');
}

// ── Protocol Switcher ──
function onProtocolChange(type) {
  document.querySelectorAll('.proto-card').forEach(c => c.classList.remove('active'));
  const card = document.getElementById('protoCard_' + type);
  if (card) card.classList.add('active');

  const port = document.getElementById('resPort');
  if (type === 'rdp') port.value = 3389;
  else if (type === 'ssh') port.value = 22;
  else if (type === 'vnc') port.value = 5900;
  else port.value = 80;
}

function autoGenerateSlug() {
  const name = document.getElementById('resName').value;
  const slugInput = document.getElementById('resSlug');
  if (!slugInput.dataset.manual) {
    slugInput.value = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
  }
}
document.getElementById('resSlug').addEventListener('input', function() {
  this.dataset.manual = 'true';
});

// ── Resource Modal ──
function openResourceModal() {
  document.getElementById('resourceForm').reset();
  document.getElementById('resId').value = '';
  document.getElementById('resSlug').dataset.manual = '';
  document.getElementById('proto_web').checked = true;
  onProtocolChange('web');
  selectAllRoles(false);
  document.getElementById('resourceModalTitle').innerText = 'Publish Internal Resource Target';
  const modal = document.getElementById('resourceModal');
  modal.style.display = 'flex';
}

function closeResourceModal() {
  document.getElementById('resourceModal').style.display = 'none';
}

// Close modal on click outside backdrop
document.getElementById('resourceModal').addEventListener('click', function(e) {
  if (e.target === this) closeResourceModal();
});

// Close modal on Escape key
window.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeResourceModal();
});

function selectAllRoles(checked) {
  document.querySelectorAll('#resourceForm input[name="allowed_roles[]"]').forEach(cb => {
    cb.checked = checked;
  });
}

function editResource(r) {
  document.getElementById('resourceForm').reset();
  document.getElementById('resId').value = r.id;
  document.getElementById('resName').value = r.name;
  document.getElementById('resSlug').value = r.slug;
  document.getElementById('resSlug').dataset.manual = 'true';
  document.getElementById('resHost').value = r.internal_host;
  document.getElementById('resPort').value = r.internal_port;
  document.getElementById('resDesc').value = r.description || '';

  const protoRadio = document.getElementById('proto_' + r.type);
  if (protoRadio) {
    protoRadio.checked = true;
    onProtocolChange(r.type);
  }

  let roles = [];
  try { roles = JSON.parse(r.allowed_roles || '[]'); } catch(e){}
  document.querySelectorAll('#resourceForm input[name="allowed_roles[]"]').forEach(cb => {
    cb.checked = roles.includes(cb.value);
  });

  document.getElementById('resourceModalTitle').innerText = 'Edit Resource Target';
  document.getElementById('resourceModal').style.display = 'flex';
}

// ── Toast Alert Helper ──
function showToast(msg, type = 'success') {
  const container = document.getElementById('ztnaAlertContainer');
  const alert = document.getElementById('ztnaAlert');
  const msgEl = document.getElementById('ztnaAlertMsg');
  const icon = document.getElementById('ztnaAlertIcon');

  msgEl.innerText = msg;
  if (type === 'success') {
    alert.style.borderLeftColor = 'var(--color-emerald)';
    icon.innerText = '✅';
  } else if (type === 'error') {
    alert.style.borderLeftColor = 'var(--color-rose)';
    icon.innerText = '⚠️';
  } else {
    alert.style.borderLeftColor = 'var(--color-indigo)';
    icon.innerText = 'ℹ️';
  }
  container.style.display = 'block';
  setTimeout(() => { container.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, 50);
}

// ── Save Resource AJAX ──
function saveResource(e) {
  e.preventDefault();
  const btn = document.getElementById('btnSaveResource');
  btn.disabled = true;
  btn.innerText = 'Saving...';

  const fd = new FormData(document.getElementById('resourceForm'));
  MicronScheduler.fetch('<?= base_url('vpn/clientless-gateway/resource/save') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.innerText = 'Save & Publish';
    if (data.csrf_hash) updateCsrf(null, data.csrf_hash);

    if (data.success) {
      closeResourceModal();
      showToast(data.message || 'Resource saved successfully.', 'success');
      setTimeout(() => window.location.reload(), 700);
    } else {
      showToast(data.message || 'Error saving resource.', 'error');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerText = 'Save & Publish';
    showToast('Network error saving resource.', 'error');
  });
}

// ── Delete Resource AJAX ──
function deleteResource(id, name) {
  if (!confirm(`Are you sure you want to delete resource '${name}'?`)) return;
  const fd = new FormData();
  const csrfInput = document.querySelector('.csrf-field');
  if (csrfInput) fd.append(csrfInput.name, csrfInput.value);

  MicronScheduler.fetch('<?= base_url('vpn/clientless-gateway/resource') ?>/' + encodeURIComponent(id) + '/delete', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.csrf_hash) updateCsrf(null, data.csrf_hash);
    if (data.success) {
      const row = document.getElementById('row_' + id);
      if (row) row.remove();
      showToast(data.message || 'Resource deleted.', 'success');
    } else {
      showToast(data.message || 'Could not delete resource.', 'error');
    }
  });
}

// ── Terminate Session AJAX ──
function terminateSession(id) {
  if (!confirm('Disconnect and terminate this remote stream session?')) return;
  const fd = new FormData();
  const csrfInput = document.querySelector('.csrf-field');
  if (csrfInput) fd.append(csrfInput.name, csrfInput.value);

  MicronScheduler.fetch('<?= base_url('vpn/clientless-gateway/session') ?>/' + encodeURIComponent(id) + '/terminate', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.csrf_hash) updateCsrf(null, data.csrf_hash);
    if (data.success) {
      const row = document.getElementById('sess_row_' + id);
      if (row) row.remove();
      showToast(data.message || 'Remote session terminated.', 'success');
    } else {
      showToast(data.message || 'Could not terminate session.', 'error');
    }
  });
}

// ── Live Branding Color Presets & Sync ──
function setBrandPalette(primary, hover, accent) {
  document.getElementById('brandColorPicker').value = primary;
  document.getElementById('brandColorText').value = primary;
  document.getElementById('brandHoverPicker').value = hover;
  document.getElementById('brandHoverText').value = hover;
  document.getElementById('brandAccentPicker').value = accent;
  document.getElementById('brandAccentText').value = accent;
  updateLivePreview();
}

function syncColorInput(val, textId) {
  document.getElementById(textId).value = val;
  updateLivePreview();
}

function syncColorPicker(val, pickerId) {
  if (/^#[0-9a-fA-F]{6}$/.test(val)) {
    document.getElementById(pickerId).value = val;
    updateLivePreview();
  }
}

// ── Portal Theme Switcher & Preview Syncer ──
function setPortalTheme(theme) {
  const themeInput = document.getElementById('brandPortalTheme');
  if (themeInput) themeInput.value = theme;

  ['Light', 'Gray', 'Dark'].forEach(t => {
    const btn = document.getElementById('btnTheme' + t);
    const prevBtn = document.getElementById('btnPrevTheme' + t);
    if (t.toLowerCase() === theme) {
      if (btn) { btn.classList.add('active'); btn.classList.remove('btn-ghost'); }
      if (prevBtn) { prevBtn.classList.add('active'); prevBtn.classList.remove('btn-ghost'); }
    } else {
      if (btn) { btn.classList.remove('active'); btn.classList.add('btn-ghost'); }
      if (prevBtn) { prevBtn.classList.remove('active'); prevBtn.classList.add('btn-ghost'); }
    }
  });

  updateLivePreview();
}

// ── Live Interactive Preview Updater ──
function updateLivePreview() {
  const company = document.getElementById('brandCompanyName').value || 'Micron Security';
  const heading = document.getElementById('brandWelcomeHeading').value || 'Zero-Trust Clientless Access Portal';
  const subtitle = document.getElementById('brandWelcomeSubtitle').value || '';
  const notice = document.getElementById('brandNoticeText').value || '';
  const noticeType = document.getElementById('brandNoticeType').value;
  const primaryColor = document.getElementById('brandColorText').value || '#6366f1';
  const logoUrl = document.getElementById('brandLogoUrl').value;
  const helpText = document.getElementById('brandHelpText').value || 'Contact Helpdesk';
  const footerText = document.getElementById('brandFooterText').value || '';

  document.getElementById('prevCompanyName').innerText = company;
  document.getElementById('prevHeroHeading').innerText = heading;
  document.getElementById('prevHeroSubtitle').innerText = subtitle;
  document.getElementById('prevHelpButton').innerText = helpText;
  document.getElementById('prevFooterContent').innerText = footerText;

  // Buttons color
  document.getElementById('prevLaunchBtn1').style.background = primaryColor;
  document.getElementById('prevLaunchBtn2').style.background = primaryColor;
  document.getElementById('prevHelpButton').style.color = primaryColor;

  // Background Theme Adaptation
  const theme = (document.getElementById('brandPortalTheme')?.value || 'light').toLowerCase();
  const mockFrame = document.getElementById('prevMockFrame');
  const mockChrome = document.getElementById('prevMockChrome');
  const mockUrlBar = document.getElementById('prevMockUrlBar');
  const heroCard = document.getElementById('prevHeroCard');
  const heroHeading = document.getElementById('prevHeroHeading');
  const heroSubtitle = document.getElementById('prevHeroSubtitle');
  const companyTitle = document.getElementById('prevCompanyName');
  const headerBorder = document.getElementById('prevHeaderBorder');
  const appsSectionTitle = document.getElementById('prevAppsSectionTitle');
  const appCards = document.querySelectorAll('.prev-app-card');
  const appTitles = document.querySelectorAll('.prev-app-title');
  const footerContent = document.getElementById('prevFooterContent');
  const footerBorder = document.getElementById('prevFooterBorder');

  if (mockFrame) {
    if (theme === 'light') {
      mockFrame.style.background = '#f8fafc';
      if (mockChrome) { mockChrome.style.background = '#f1f5f9'; mockChrome.style.borderColor = '#e2e8f0'; }
      if (mockUrlBar) { mockUrlBar.style.background = '#ffffff'; mockUrlBar.style.color = '#334155'; }
      if (companyTitle) companyTitle.style.color = '#0f172a';
      if (headerBorder) headerBorder.style.borderColor = '#e2e8f0';
      if (heroCard) {
        heroCard.style.background = '#ffffff';
        heroCard.style.borderColor = '#e2e8f0';
        heroCard.style.boxShadow = '0 4px 12px rgba(0,0,0,0.04)';
      }
      if (heroHeading) heroHeading.style.color = '#0f172a';
      if (heroSubtitle) heroSubtitle.style.color = '#475569';
      if (appsSectionTitle) appsSectionTitle.style.color = '#334155';
      if (footerContent) footerContent.style.color = '#64748b';
      if (footerBorder) footerBorder.style.borderColor = '#e2e8f0';
      appCards.forEach(c => {
        c.style.background = '#ffffff';
        c.style.borderColor = '#e2e8f0';
        c.style.boxShadow = '0 2px 6px rgba(0,0,0,0.04)';
      });
      appTitles.forEach(t => t.style.color = '#0f172a');
    } else if (theme === 'gray') {
      mockFrame.style.background = '#f1f5f9';
      if (mockChrome) { mockChrome.style.background = '#e2e8f0'; mockChrome.style.borderColor = '#cbd5e1'; }
      if (mockUrlBar) { mockUrlBar.style.background = '#ffffff'; mockUrlBar.style.color = '#1e293b'; }
      if (companyTitle) companyTitle.style.color = '#0f172a';
      if (headerBorder) headerBorder.style.borderColor = '#cbd5e1';
      if (heroCard) {
        heroCard.style.background = '#ffffff';
        heroCard.style.borderColor = '#cbd5e1';
        heroCard.style.boxShadow = '0 4px 12px rgba(0,0,0,0.05)';
      }
      if (heroHeading) heroHeading.style.color = '#0f172a';
      if (heroSubtitle) heroSubtitle.style.color = '#475569';
      if (appsSectionTitle) appsSectionTitle.style.color = '#334155';
      if (footerContent) footerContent.style.color = '#64748b';
      if (footerBorder) footerBorder.style.borderColor = '#cbd5e1';
      appCards.forEach(c => {
        c.style.background = '#ffffff';
        c.style.borderColor = '#cbd5e1';
        c.style.boxShadow = '0 2px 6px rgba(0,0,0,0.04)';
      });
      appTitles.forEach(t => t.style.color = '#0f172a');
    } else {
      // Dark Midnight Navy
      mockFrame.style.background = '#070b14';
      if (mockChrome) { mockChrome.style.background = '#0f172a'; mockChrome.style.borderColor = 'rgba(255,255,255,0.08)'; }
      if (mockUrlBar) { mockUrlBar.style.background = 'rgba(255,255,255,0.06)'; mockUrlBar.style.color = '#94a3b8'; }
      if (companyTitle) companyTitle.style.color = '#f8fafc';
      if (headerBorder) headerBorder.style.borderColor = 'rgba(255,255,255,0.08)';
      if (heroCard) {
        heroCard.style.background = 'linear-gradient(135deg, rgba(99,102,241,0.12), rgba(16,185,129,0.06))';
        heroCard.style.borderColor = 'rgba(99,102,241,0.3)';
        heroCard.style.boxShadow = 'none';
      }
      if (heroHeading) heroHeading.style.color = '#f8fafc';
      if (heroSubtitle) heroSubtitle.style.color = '#94a3b8';
      if (appsSectionTitle) appsSectionTitle.style.color = '#cbd5e1';
      if (footerContent) footerContent.style.color = '#64748b';
      if (footerBorder) footerBorder.style.borderColor = 'rgba(255,255,255,0.06)';
      appCards.forEach(c => {
        c.style.background = 'rgba(255,255,255,0.03)';
        c.style.borderColor = 'rgba(255,255,255,0.08)';
        c.style.boxShadow = 'none';
      });
      appTitles.forEach(t => t.style.color = '#f1f5f9');
    }
  }

  // Logo box
  const logoBox = document.getElementById('prevLogoBox');
  if (logoUrl) {
    logoBox.innerHTML = `<img src="${logoUrl}" style="width:100%; height:100%; object-fit:contain; border-radius:4px;" onerror="this.parentElement.innerText='${company.charAt(0)}'">`;
    logoBox.style.background = 'transparent';
  } else {
    logoBox.innerHTML = company.charAt(0).toUpperCase();
    logoBox.style.background = primaryColor;
  }

  // Notice banner
  const prevNotice = document.getElementById('prevNoticeBanner');
  if (notice.trim()) {
    prevNotice.style.display = 'flex';
    document.getElementById('prevNoticeContent').innerText = notice;
    if (noticeType === 'warning') {
      prevNotice.style.background = 'rgba(245,158,11,0.15)';
      prevNotice.style.color = '#fbbf24';
      prevNotice.style.borderColor = 'rgba(245,158,11,0.3)';
    } else if (noticeType === 'danger') {
      prevNotice.style.background = 'rgba(239,68,68,0.15)';
      prevNotice.style.color = '#f87171';
      prevNotice.style.borderColor = 'rgba(239,68,68,0.3)';
    } else if (noticeType === 'success') {
      prevNotice.style.background = 'rgba(16,185,129,0.15)';
      prevNotice.style.color = '#34d399';
      prevNotice.style.borderColor = 'rgba(16,185,129,0.3)';
    } else {
      prevNotice.style.background = 'rgba(59,130,246,0.15)';
      prevNotice.style.color = '#60a5fa';
      prevNotice.style.borderColor = 'rgba(59,130,246,0.3)';
    }
  } else {
    prevNotice.style.display = 'none';
  }
}

// ── Live Preview Mode Switcher (Card vs List) ──
function togglePreviewMode(mode) {
  const container = document.getElementById('prevAppsGrid');
  const btnCard = document.getElementById('btnPrevModeCard');
  const btnList = document.getElementById('btnPrevModeList');
  if (!container || !btnCard || !btnList) return;

  if (mode === 'list') {
    container.style.display = 'flex';
    container.style.flexDirection = 'column';
    container.style.gap = '6px';
    btnList.classList.add('active');
    btnList.classList.remove('btn-ghost');
    btnCard.classList.remove('active');
    btnCard.classList.add('btn-ghost');
  } else {
    container.style.display = 'grid';
    container.style.flexDirection = '';
    container.style.gridTemplateColumns = '1fr 1fr';
    container.style.gap = '8px';
    btnCard.classList.add('active');
    btnCard.classList.remove('btn-ghost');
    btnList.classList.remove('active');
    btnList.classList.add('btn-ghost');
  }
}


// ── Save Branding AJAX ──
function saveBranding(e) {
  e.preventDefault();
  const btn = document.getElementById('btnSaveBranding');
  btn.disabled = true;
  btn.innerText = 'Saving Branding...';

  const fd = new FormData(document.getElementById('ztnaBrandingForm'));
  MicronScheduler.fetch('<?= base_url('vpn/clientless-gateway/settings/save') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.innerText = 'Save Company Branding';
    if (data.csrf_hash) updateCsrf(null, data.csrf_hash);
    if (data.success) {
      showToast(data.message || 'Branding saved successfully.', 'success');
    } else {
      showToast(data.message || 'Error saving branding.', 'error');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerText = 'Save Company Branding';
    showToast('Failed to save branding.', 'error');
  });
}

// ── Reset Branding Defaults ──
function resetBrandingDefaults() {
  if (!confirm('Reset all company branding back to defaults?')) return;
  const fd = new FormData();
  const csrfInput = document.querySelector('.csrf-field');
  if (csrfInput) fd.append(csrfInput.name, csrfInput.value);

  MicronScheduler.fetch('<?= base_url('vpn/clientless-gateway/branding/reset') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.csrf_hash) updateCsrf(null, data.csrf_hash);
    if (data.success) {
      showToast('Branding reset to defaults.', 'success');
      setTimeout(() => window.location.reload(), 600);
    }
  });
}

// ── Save Core Daemon Settings AJAX ──
function saveCoreSettings(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('ztnaCoreSettingsForm'));
  MicronScheduler.fetch('<?= base_url('vpn/clientless-gateway/settings/save') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.csrf_hash) updateCsrf(null, data.csrf_hash);
    if (data.success) {
      showToast(data.message || 'Daemon & Gateway settings saved successfully.', 'success');
    } else {
      showToast(data.message || 'Error saving settings.', 'error');
    }
  });
}

// Reopen the tab named in the URL hash (e.g. after a certificate change).
document.addEventListener('DOMContentLoaded', () => {
  const tab = (window.location.hash || '').replace('#', '');
  const btn = document.getElementById('tabBtn' + tab.charAt(0).toUpperCase() + tab.slice(1));
  if (btn && document.getElementById('ztna-tab-' + tab)) switchZtnaTab(tab, btn);
});

// ── Gateway access help ──
function openGwAccessHelp() {
  const m = document.getElementById('gwAccessHelp');
  m.style.display = 'flex';
  m.querySelector('button[aria-label="Close"]').focus();
}
function closeGwAccessHelp() {
  document.getElementById('gwAccessHelp').style.display = 'none';
}
document.getElementById('gwAccessHelp').addEventListener('click', function (e) {
  if (e.target === this) closeGwAccessHelp();
});
window.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') closeGwAccessHelp();
});

// ── Gateway HTTPS certificate ──
function gwCertSubmit(e, kind) {
  e.preventDefault();
  const form = e.target;
  const btn = form.querySelector('button[type=submit]');
  const label = btn.innerText;
  if (kind === 'acme' && !confirm("Request a Let's Encrypt certificate now? This opens WAN port 80 to the gateway's validation listener and can take up to a minute.")) return;
  btn.disabled = true;
  btn.innerText = kind === 'acme' ? 'Contacting Let\'s Encrypt…' : 'Installing…';
  MicronScheduler.fetch('<?= base_url('vpn/clientless-gateway/certificate') ?>/' + kind, {
    method: 'POST',
    body: new FormData(form),
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.csrf_hash) updateCsrf(null, data.csrf_hash);
    showToast(data.message || (data.success ? 'Certificate installed.' : 'The certificate was not installed.'), data.success ? 'success' : 'error');
    if (data.success) setTimeout(() => { window.location.hash = 'settings'; window.location.reload(); }, 1500);
  })
  .catch(() => showToast('The request failed. Check the appliance logs.', 'error'))
  .finally(() => { btn.disabled = false; btn.innerText = label; });
}

// ── Test guacd Connection AJAX ──
function testGuacdConnection() {
  const host = document.getElementById('guacdHostInput').value || '127.0.0.1';
  const port = document.getElementById('guacdPortInput').value || '4822';
  const box = document.getElementById('guacdTestResultBox');
  box.style.display = 'block';
  box.style.background = 'rgba(99,102,241,0.15)';
  box.style.color = '#818cf8';
  box.style.border = '1px solid rgba(99,102,241,0.3)';
  box.innerHTML = 'Testing socket handshake to guacd daemon at ' + host + ':' + port + '...';

  const fd = new FormData();
  fd.append('guacd_host', host);
  fd.append('guacd_port', port);
  const csrfInput = document.querySelector('.csrf-field');
  if (csrfInput) fd.append(csrfInput.name, csrfInput.value);

  MicronScheduler.fetch('<?= base_url('vpn/clientless-gateway/guacd/test') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.csrf_hash) updateCsrf(null, data.csrf_hash);
    if (data.success) {
      box.style.background = 'rgba(16,185,129,0.15)';
      box.style.color = '#34d399';
      box.style.border = '1px solid rgba(16,185,129,0.3)';
      box.innerHTML = `<strong>✅ guacd Daemon Handshake Succeeded!</strong><br>${data.message}`;
      const diagBadge = document.getElementById('diagGuacdStatus');
      if (diagBadge) {
        diagBadge.className = 'badge badge-emerald';
        diagBadge.innerText = 'ONLINE (' + data.latency_ms + 'ms)';
      }
    } else {
      box.style.background = 'rgba(239,68,68,0.15)';
      box.style.color = '#f87171';
      box.style.border = '1px solid rgba(239,68,68,0.3)';
      box.innerHTML = `<strong>❌ guacd Connection Probe Failed:</strong><br>${data.message || data.error}`;
      const diagBadge = document.getElementById('diagGuacdStatus');
      if (diagBadge) {
        diagBadge.className = 'badge badge-danger';
        diagBadge.innerText = 'UNREACHABLE';
      }
    }
  })
  .catch(err => {
    box.style.background = 'rgba(239,68,68,0.15)';
    box.style.color = '#f87171';
    box.innerHTML = 'Network error while attempting guacd socket probe.';
  });
}

// ── Apply & Reload Gateway Proxy AJAX ──
function applyZtnaGateway() {
  const btn = document.getElementById('btnApplyZtna');
  btn.disabled = true;
  btn.innerText = 'Reloading Proxy...';

  const fd = new FormData();
  const csrfInput = document.querySelector('.csrf-field');
  if (csrfInput) fd.append(csrfInput.name, csrfInput.value);

  MicronScheduler.fetch('<?= base_url('vpn/clientless-gateway/apply') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = `<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Apply &amp; Reload Proxy`;
    if (data.csrf_hash) updateCsrf(null, data.csrf_hash);
    if (data.success) {
      showToast(data.message || 'Clientless Gateway reverse proxy reloaded successfully.', 'success');
    } else {
      showToast(data.message || 'Error applying proxy configuration.', 'error');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = `Apply &amp; Reload Proxy`;
    showToast('Failed to apply Gateway proxy.', 'error');
  });
}

// Initial preview rendering
document.addEventListener('DOMContentLoaded', function() {
  updateLivePreview();
});
</script>

<?= $this->endSection() ?>
