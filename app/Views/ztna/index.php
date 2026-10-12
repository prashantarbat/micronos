<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <span>VPN</span>
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
    <p class="page-subtitle">Publish internal web applications, Windows Desktops (RDP), Linux Shells (SSH), and virtual consoles (VNC) securely over standard browsers with zero endpoint agents.</p>
  </div>
  <div class="page-header-actions">
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

    <button type="button" onclick="applyZtnaGateway()" class="btn btn-success" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
      </svg>
      Apply &amp; Reload Proxy
    </button>
  </div>
</div>

<!-- ── KPI Summary Cards ── -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:24px;" class="animate-in animate-in-delay-1">
  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-emerald);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Gateway Core State</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="width:10px; height:10px; border-radius:50%; background:var(--color-emerald); display:inline-block; box-shadow:0 0 8px var(--color-emerald);"></span>
      <span style="font-size:1.15rem; font-weight:700; color:var(--text-primary);">
        <?= ($settings['enabled'] ?? 1) ? 'ONLINE / PROXYING' : 'DISABLED' ?>
      </span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Nginx Auth-Subrequest + guacd Bridge</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-indigo, #6366f1);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Published Resources</div>
    <div style="font-size:1.35rem; font-weight:800; color:var(--text-primary); margin-top:4px;">
      <?= count($resources) ?> Internal Apps
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Web, Windows RDP, Linux SSH, VNC</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-amber, #f59e0b);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Active Remote Sessions</div>
    <div style="font-size:1.35rem; font-weight:800; color:var(--text-primary); margin-top:4px;">
      <?= count($sessions) ?> Live Connections
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">In-Browser HTML5 Canvas Stream</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-cyan, #06b6d4);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">External Gateway Domain</div>
    <div style="font-size:1.05rem; font-weight:700; color:var(--color-cyan); margin-top:4px; font-family:monospace; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
      <?= esc($settings['external_domain'] ?? 'gateway.micronos.local') ?>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Zero-Trust Micro-Segmentation</div>
  </div>
</div>

<!-- ── Tab Navigation ── -->
<div class="tab-bar ztna-tabs animate-in animate-in-delay-2" style="margin-bottom:20px;">
  <button type="button" class="tab-item tab-btn active" onclick="switchZtnaTab('resources', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
    Published Resource Catalogue
  </button>
  <button type="button" class="tab-item tab-btn" onclick="switchZtnaTab('sessions', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197"/></svg>
    Active User Sessions
  </button>
  <button type="button" class="tab-item tab-btn" onclick="switchZtnaTab('settings', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/></svg>
    Gateway Configuration &amp; guacd
  </button>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1: PUBLISHED RESOURCE CATALOGUE                                    -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="ztna-tab-resources" class="tab-pane active animate-in">
  <div class="card card-glass" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--border-color);">
      <div>
        <h3 style="margin:0; font-size:1rem; font-weight:700; color:var(--text-primary);">Authorized Internal Applications &amp; Terminals</h3>
        <p style="margin:4px 0 0; font-size:0.78rem; color:var(--text-muted);">Users authenticate once at the portal, then access these internal targets via reverse proxy or HTML5 remote canvas without network VPN tunneling.</p>
      </div>
      <button type="button" class="btn btn-sm btn-primary" onclick="openResourceModal()">+ Publish Resource</button>
    </div>
    <div class="table-responsive">
      <table class="table" style="margin:0; width:100%; border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color); background:var(--bg-card-header, rgba(255,255,255,0.02));">
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Resource Name</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Protocol Type</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Target Host &amp; Port</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Allowed RBAC Roles</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:center;">Live Sessions</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($resources as $res): ?>
            <?php $allowed = json_decode($res['allowed_roles'] ?? '[]', true) ?: []; ?>
            <tr style="border-bottom:1px solid var(--border-color);">
              <td style="padding:14px 18px;">
                <div style="font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
                  <?php if ($res['type'] === 'rdp'): ?>
                    <span style="color:#60a5fa;">💻</span>
                  <?php elseif ($res['type'] === 'ssh'): ?>
                    <span style="color:#34d399;">⌨️</span>
                  <?php elseif ($res['type'] === 'vnc'): ?>
                    <span style="color:#fbbf24;">🖥️</span>
                  <?php else: ?>
                    <span style="color:#818cf8;">🌐</span>
                  <?php endif; ?>
                  <?= esc($res['name']) ?>
                </div>
                <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
                  /apps/<?= esc($res['slug']) ?> &bull; <?= esc($res['description'] ?: 'Zero trust protected') ?>
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
                <div style="display:flex; flex-wrap:wrap; gap:4px; max-width:240px;">
                  <?php if (empty($allowed)): ?>
                    <span class="badge" style="background:rgba(100,116,139,0.2); font-size:0.7rem;">All Authenticated</span>
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
                <a href="<?= base_url('vpn/clientless-gateway/launch/' . esc($res['id'])) ?>" target="_blank" class="btn btn-xs btn-primary" title="Launch Remote Session">
                  Launch &rarr;
                </a>
                <button type="button" class="btn btn-xs btn-ghost" onclick='editResource(<?= json_encode($res) ?>)' title="Edit Resource">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                </button>
                <button type="button" class="btn btn-xs btn-ghost text-danger" onclick="deleteResource('<?= esc($res['id']) ?>', '<?= esc($res['name']) ?>')" title="Delete Resource">
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
<!-- TAB 2: ACTIVE USER SESSIONS                                            -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="ztna-tab-sessions" class="tab-pane animate-in" style="display:none;">
  <div class="card card-glass" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--border-color);">
      <div>
        <h3 style="margin:0; font-size:1rem; font-weight:700; color:var(--text-primary);">Active Clientless Sessions</h3>
        <p style="margin:4px 0 0; font-size:0.78rem; color:var(--text-muted);">Users currently connected to internal desktop, terminal, or web application sessions.</p>
      </div>
      <span class="badge badge-indigo"><?= count($sessions) ?> Active Sessions</span>
    </div>
    <div class="table-responsive">
      <table class="table" style="margin:0; width:100%; border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color); background:var(--bg-card-header, rgba(255,255,255,0.02));">
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Username</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Resource ID</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Client IP</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Started At</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sessions)): ?>
            <tr>
              <td colspan="5" style="padding:32px 18px; text-align:center; color:var(--text-muted); font-size:0.82rem;">
                No clientless sessions currently connected. Launch a remote session from the User Portal to monitor live streams.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($sessions as $s): ?>
              <tr style="border-bottom:1px solid var(--border-color);">
                <td style="padding:12px 18px; font-weight:600; color:var(--text-primary);"><?= esc($s['username']) ?></td>
                <td style="padding:12px 18px; font-family:monospace; color:var(--color-cyan);"><?= esc($s['resource_id']) ?></td>
                <td style="padding:12px 18px; font-family:monospace;"><?= esc($s['client_ip']) ?></td>
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
<!-- TAB 3: GATEWAY CONFIGURATION                                           -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="ztna-tab-settings" class="tab-pane animate-in" style="display:none;">
  <div class="card card-glass" style="max-width:680px; padding:24px;">
    <h3 style="margin:0 0 16px; font-size:1.05rem; font-weight:700; color:var(--text-primary);">Clientless Gateway &amp; guacd Daemon Settings</h3>
    <form id="ztnaSettingsForm" onsubmit="saveZtnaSettings(event)">
      <?= csrf_field() ?>
      <div style="margin-bottom:16px;">
        <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
          <input type="checkbox" name="enabled" value="1" <?= ($settings['enabled'] ?? 1) ? 'checked' : '' ?> style="width:16px; height:16px;">
          <div>
            <div style="font-weight:600; font-size:0.85rem; color:var(--text-primary);">Enable In-Browser Clientless Gateway Core</div>
            <div style="font-size:0.75rem; color:var(--text-muted);">Enables Nginx reverse proxy subrequest authentication and Guacamole WebSocket bridge.</div>
          </div>
        </label>
      </div>

      <div style="margin-bottom:14px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Portal Title</label>
        <input type="text" name="portal_title" class="form-control" value="<?= esc($settings['portal_title'] ?? 'Micron OS Clientless Gateway') ?>">
      </div>

      <div style="margin-bottom:14px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">External Gateway FQDN / Domain</label>
        <input type="text" name="external_domain" class="form-control" value="<?= esc($settings['external_domain'] ?? 'gateway.micronos.local') ?>">
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">guacd Daemon Host</label>
          <input type="text" name="guacd_host" class="form-control" value="<?= esc($settings['guacd_host'] ?? '127.0.0.1') ?>">
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">guacd Daemon Port</label>
          <input type="number" name="guacd_port" class="form-control" value="<?= esc($settings['guacd_port'] ?? 4822) ?>">
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:18px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Session Inactivity Timeout (Minutes)</label>
          <input type="number" name="session_timeout_mins" class="form-control" value="<?= esc($settings['session_timeout_mins'] ?? 60) ?>" min="5" max="480">
        </div>
        <div style="display:flex; align-items:center;">
          <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; cursor:pointer;">
            <input type="checkbox" name="require_mfa" value="1" <?= ($settings['require_mfa'] ?? 1) ? 'checked' : '' ?>>
            Mandate TOTP Two-Factor Authentication
          </label>
        </div>
      </div>

      <button type="submit" class="btn btn-primary">Save Gateway Settings</button>
    </form>
  </div>
</div>

<!-- ── MODAL: Resource Modal ── -->
<div id="resourceModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="card card-glass" style="width:95%; max-width:560px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-primary);" id="resourceModalTitle">Publish Resource</h3>
      <button type="button" class="btn btn-xs btn-ghost" onclick="closeResourceModal()">&times;</button>
    </div>
    <form id="resourceForm" onsubmit="saveResource(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="resId" value="">
      <div style="margin-bottom:12px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Resource Name</label>
        <input type="text" name="name" id="resName" class="form-control" required placeholder="e.g. Production PostgreSQL Bastion">
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Protocol Type</label>
          <select name="type" id="resType" class="form-select" onchange="updateDefaultPort()">
            <option value="web">Web Application (HTTP/HTTPS Proxy)</option>
            <option value="rdp">Windows Desktop (HTML5 RDP)</option>
            <option value="ssh">Linux Terminal (HTML5 SSH)</option>
            <option value="vnc">Virtual Console (HTML5 VNC)</option>
          </select>
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">URL Slug</label>
          <input type="text" name="slug" id="resSlug" class="form-control" placeholder="auto-generated-slug">
        </div>
      </div>
      <div style="display:grid; grid-template-columns:2fr 1fr; gap:12px; margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Internal IP / Hostname</label>
          <input type="text" name="internal_host" id="resHost" class="form-control" required placeholder="192.168.10.25">
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Internal Port</label>
          <input type="number" name="internal_port" id="resPort" class="form-control" value="80">
        </div>
      </div>
      <div style="margin-bottom:12px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Description</label>
        <input type="text" name="description" id="resDesc" class="form-control" placeholder="Purpose and department access scope">
      </div>
      <div style="margin-bottom:14px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Allowed Roles</label>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; background:rgba(0,0,0,0.2); padding:10px; border-radius:6px;">
          <?php foreach ($allRoles as $role): ?>
            <label style="font-size:0.75rem; display:flex; align-items:center; gap:6px;">
              <input type="checkbox" name="allowed_roles[]" value="<?= esc($role['name']) ?>" id="role_<?= esc($role['id']) ?>">
              <?= esc($role['name']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeResourceModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Resource</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchZtnaTab(tab, el) {
  document.querySelectorAll('.tab-pane').forEach(p => p.style.display = 'none');
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  const target = document.getElementById('ztna-tab-' + tab);
  if (target) target.style.display = 'block';
  if (el) el.classList.add('active');
}

function updateDefaultPort() {
  const t = document.getElementById('resType').value;
  const p = document.getElementById('resPort');
  if (t === 'rdp') p.value = 3389;
  else if (t === 'ssh') p.value = 22;
  else if (t === 'vnc') p.value = 5900;
  else p.value = 80;
}

function openResourceModal() {
  document.getElementById('resourceForm').reset();
  document.getElementById('resId').value = '';
  document.getElementById('resourceModalTitle').innerText = 'Publish Resource';
  document.getElementById('resourceModal').style.display = 'flex';
}
function closeResourceModal() {
  document.getElementById('resourceModal').style.display = 'none';
}
function editResource(r) {
  document.getElementById('resourceForm').reset();
  document.getElementById('resId').value = r.id;
  document.getElementById('resName').value = r.name;
  document.getElementById('resType').value = r.type;
  document.getElementById('resSlug').value = r.slug;
  document.getElementById('resHost').value = r.internal_host;
  document.getElementById('resPort').value = r.internal_port;
  document.getElementById('resDesc').value = r.description || '';

  let roles = [];
  try { roles = JSON.parse(r.allowed_roles || '[]'); } catch(e){}
  document.querySelectorAll('#resourceForm input[name="allowed_roles[]"]').forEach(cb => {
    cb.checked = roles.includes(cb.value);
  });

  document.getElementById('resourceModalTitle').innerText = 'Edit Resource';
  document.getElementById('resourceModal').style.display = 'flex';
}

function saveResource(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('resourceForm'));
  MicronScheduler.fetch('<?= base_url('vpn/clientless-gateway/resource/save') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      window.location.reload();
    } else {
      alert(data.message || 'Error saving resource.');
    }
  });
}

function deleteResource(id, name) {
  if (!confirm(`Delete resource '${name}'?`)) return;
  const fd = new FormData();
  fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');
  MicronScheduler.fetch('<?= base_url('vpn/clientless-gateway/resource') ?>/' + id + '/delete', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) window.location.reload();
  });
}

function terminateSession(id) {
  if (!confirm('Disconnect and terminate this remote session?')) return;
  const fd = new FormData();
  fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');
  MicronScheduler.fetch('<?= base_url('vpn/clientless-gateway/session') ?>/' + id + '/terminate', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) window.location.reload();
  });
}

function saveZtnaSettings(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('ztnaSettingsForm'));
  MicronScheduler.fetch('<?= base_url('vpn/clientless-gateway/settings/save') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    alert(data.message || 'Settings saved.');
  });
}

function applyZtnaGateway() {
  if (!confirm('Compile and apply Clientless Gateway Nginx reverse proxy configuration?')) return;
  const fd = new FormData();
  fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');
  MicronScheduler.fetch('<?= base_url('vpn/clientless-gateway/apply') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    alert(data.message || 'Gateway reverse proxy applied.');
  });
}
</script>

<?= $this->endSection() ?>
