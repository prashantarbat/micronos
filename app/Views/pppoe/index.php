<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?php $canManage = session()->get('is_superadmin') || in_array('pppoe.server.manage', session()->get('permissions') ?? []); ?>

<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <span>Interfaces</span>
      <span class="sep">/</span>
      <span class="current">PPPoE Server</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-emerald">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/>
        </svg>
      </div>
      PPPoE Server
    </h1>
    <p class="page-subtitle">Run an access concentrator on a local interface and authenticate subscribers.</p>
  </div>
  <?php if ($canManage): ?>
    <div class="page-header-actions">
      <form method="POST" action="<?= base_url('pppoe/apply') ?>" style="margin:0;"><?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost btn-sm">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
          Apply PPPoE Configuration
        </button>
      </form>
      <button type="button" class="btn btn-primary btn-sm" onclick="openServerModal()">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Server
      </button>
    </div>
  <?php endif; ?>
</div>

<?php if (!empty($isMock)): ?>
  <div class="card animate-in animate-in-delay-1" style="padding:12px 16px; margin-bottom:16px; border-left:3px solid var(--color-amber); font-size:0.8rem; color:var(--text-secondary);">
    micrond is not reachable, so applying is simulated. Configuration is saved and rendered as usual.
  </div>
<?php endif; ?>

<!-- ================================================================
     Live Session Monitor
     ================================================================ -->
<div class="card card-glass animate-in animate-in-delay-1" style="margin-bottom:22px;">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <span class="card-title" style="display:flex; align-items:center; gap:10px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12h4l3 8 4-16 3 8h4"/></svg>
        Live Sessions
      </span>
      <!-- Link-State Indicators reusing health check -->
      <div id="linkStateContainer" style="display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap;">
        <?php foreach ($linkHealth['links'] ?? [] as $lh): ?>
          <span class="badge <?= esc($lh['badge_class']) ?>" style="display:inline-flex; align-items:center; gap:6px; font-size:0.72rem; padding:3px 8px;" id="linkBadge-<?= esc($lh['interface']) ?>" title="<?= esc($lh['status_tooltip'] ?? '') ?>">
            <span class="<?= $lh['state'] === 'UP' ? 'pulse-dot' : 'status-dot dot-' . ($lh['state'] === 'BACKOFF' ? 'warning' : 'danger') ?>"></span>
            <?= esc($lh['name'] ?: $lh['interface']) ?>: <?= esc($lh['state']) ?>
            <?php if ($lh['state'] === 'BACKOFF' && !empty($lh['remaining_human'])): ?>
              (<?= esc($lh['remaining_human']) ?>)
            <?php endif; ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
    <div style="display:flex; gap:8px; align-items:center; font-size:0.75rem; color:var(--text-muted); flex-wrap:wrap;">
      <span><?= (int) $sessionSummary['client'] ?> client link(s)</span>
      <span>&bull;</span>
      <span><?= (int) $sessionSummary['server'] ?> subscriber session(s)</span>
      <span>&bull;</span>
      <span><?= (int) $sessionSummary['up'] ?> up</span>
      <button type="button" class="btn btn-outline-primary btn-sm" onclick="redialNow()" id="btnRedialNow" style="display:inline-flex; align-items:center; gap:6px;" title="Redial down PPPoE WAN links immediately, bypassing backoff">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        Redial now
      </button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="refreshSessions()" id="sessRefresh">Refresh</button>
    </div>
  </div>
  <div class="card-body" id="sessionBody">
    <?= $this->include('pppoe/_sessions') ?>
  </div>
</div>

<?php if (empty($servers)): ?>
  <div class="card animate-in animate-in-delay-1" style="padding:36px; text-align:center; color:var(--text-muted);">
    <div style="font-weight:600; color:var(--text-secondary); margin-bottom:6px;">No PPPoE server configured</div>
    <div style="font-size:0.82rem;">Add a server to hand out addresses to PPPoE subscribers on a local interface.</div>
  </div>
<?php else: ?>
  <?php foreach ($servers as $i => $server): ?>
    <div class="card card-glass animate-in animate-in-delay-1" style="margin-bottom:20px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
        <span class="card-title" style="display:flex; align-items:center; gap:10px;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728M8.464 15.536a5 5 0 010-7.072m7.072 0a5 5 0 010 7.072M12 12h.01"/></svg>
          <?= esc($server['name']) ?>
          <?php if ($server['active']): ?>
            <span class="badge badge-success" style="font-size:0.68rem;">ACTIVE</span>
          <?php else: ?>
            <span class="badge badge-muted" style="font-size:0.68rem;">DISABLED</span>
          <?php endif; ?>
        </span>
        <?php if ($canManage): ?>
          <div style="display:flex; gap:6px;">
            <?php if (!empty($server['uses_radius'])): ?>
              <button type="button" class="btn btn-ghost btn-sm" onclick='openRadiusTest(<?= htmlspecialchars(json_encode($server['id']), ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars(json_encode($server['name']), ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars(json_encode($server['radius_server']), ENT_QUOTES, "UTF-8") ?>)'>Test RADIUS</button>
            <?php endif; ?>
            <button type="button" class="btn btn-ghost btn-sm" onclick='openUserModal(<?= htmlspecialchars(json_encode($server['id']), ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars(json_encode($server['name']), ENT_QUOTES, "UTF-8") ?>)'>Add Subscriber</button>
            <form method="POST" action="<?= base_url('pppoe/servers/' . esc($server['id']) . '/toggle') ?>" style="display:inline;"><?= csrf_field() ?>
              <button type="submit" class="btn btn-ghost btn-sm"><?= $server['active'] ? 'Disable' : 'Enable' ?></button>
            </form>
            <button type="button" class="btn btn-ghost btn-sm" onclick='openEditServer(<?= htmlspecialchars(json_encode($server), ENT_QUOTES, "UTF-8") ?>)'>Edit</button>
            <form method="POST" action="<?= base_url('pppoe/servers/' . esc($server['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove this PPPoE server and all of its subscribers?')"><?= csrf_field() ?>
              <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-red);">Delete</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:18px;">
          <div>
            <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase;">Interface</div>
            <div style="font-family:var(--font-mono); font-size:0.85rem; color:var(--text-primary);"><?= esc($server['interface']) ?></div>
          </div>
          <div>
            <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase;">Service / AC Name</div>
            <div style="font-family:var(--font-mono); font-size:0.85rem; color:var(--text-primary);">
              <?= esc($server['service_name']) ?><?= $server['ac_name'] ? ' / ' . esc($server['ac_name']) : '' ?>
            </div>
          </div>
          <div>
            <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase;">Local Address</div>
            <div style="font-family:var(--font-mono); font-size:0.85rem; color:var(--text-primary);"><?= esc($server['local_ip']) ?></div>
          </div>
          <div>
            <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase;">Client Pool</div>
            <div style="font-family:var(--font-mono); font-size:0.85rem; color:var(--text-primary);">
              <?= esc($server['remote_start']) ?> &ndash; <?= esc($server['remote_end']) ?>
            </div>
          </div>
          <div>
            <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase;">Authentication</div>
            <div style="font-size:0.85rem; color:var(--text-primary);">
              <?php if (!empty($server['uses_radius'])): ?>
                <span class="badge badge-cyan" style="font-size:0.68rem;">RADIUS</span>
                <span style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-muted); margin-left:4px;">
                  <?= esc($server['radius_server']) ?>:<?= (int) $server['radius_port'] ?>
                </span>
              <?php else: ?>
                <span class="badge badge-muted" style="font-size:0.68rem;">LOCAL</span>
              <?php endif; ?>
            </div>
          </div>
          <div>
            <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase;">Accounting</div>
            <div style="font-size:0.85rem; color:var(--text-primary);">
              <?php if (!empty($server['uses_accounting'])): ?>
                <span class="badge badge-success" style="font-size:0.68rem;">ON</span>
                <span style="font-family:var(--font-mono); font-size:0.72rem; color:var(--text-muted); margin-left:4px;">
                  <?= esc(implode(', ', $server['acct_targets'] ?? [])) ?>
                </span>
                <?php if (empty($server['acct_applied'])): ?>
                  <?php // Configured but absent from the applied configuration. ?>
                  <span class="badge badge-muted" style="font-size:0.62rem; margin-left:4px;" title="Enabled in the database but not present in the applied RADIUS configuration — apply to activate.">
                    pending apply
                  </span>
                <?php endif; ?>
              <?php else: ?>
                <span class="badge badge-muted" style="font-size:0.68rem;">OFF</span>
              <?php endif; ?>
            </div>
          </div>
          <div>
            <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase;">Subscribers</div>
            <div style="font-size:0.85rem; color:var(--text-primary);"><?= (int) $server['enabled_users'] ?> active / <?= (int) $server['user_count'] ?> total</div>
          </div>
        </div>

        <?php if (!empty($server['description'])): ?>
          <div style="font-size:0.78rem; color:var(--text-muted); margin-bottom:16px;"><?= esc($server['description']) ?></div>
        <?php endif; ?>

        <div class="table-responsive">
          <table class="iface-table" style="width:100%;">
            <thead>
              <tr>
                <th>Subscriber</th><th>Fixed Address</th><th>DNS</th><th>Rate Limit</th><th>Status</th><th style="text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($server['users'])): ?>
                <tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted); font-size:0.82rem;">No subscribers yet.</td></tr>
              <?php else: ?>
                <?php foreach ($server['users'] as $user): ?>
                  <tr>
                    <td style="font-family:var(--font-mono); font-size:0.82rem; color:var(--text-primary);">
                      <a href="<?= base_url('pppoe/users/' . esc($user['id']) . '/history') ?>" style="color:var(--text-primary); text-decoration:none; font-weight:600;" title="View RADIUS attribute & limit change history">
                        <?= esc($user['username']) ?>
                      </a>
                    </td>
                    <td style="font-family:var(--font-mono); font-size:0.8rem;"><?= esc($user['static_ip'] ?: 'From pool') ?></td>
                    <td style="font-size:0.78rem;"><?= esc($user['dns1'] ?: '—') ?></td>
                    <td style="font-size:0.78rem;"><?= esc($user['rate_limit'] ?: '—') ?></td>
                    <td>
                      <?php if ((int) $user['enabled'] === 1): ?>
                        <span class="badge badge-success" style="font-size:0.68rem;">ENABLED</span>
                      <?php else: ?>
                        <span class="badge badge-muted" style="font-size:0.68rem;">DISABLED</span>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                      <a href="<?= base_url('pppoe/users/' . esc($user['id']) . '/history') ?>" class="btn btn-ghost btn-sm" style="padding:3px 8px; color:var(--color-cyan);" title="View RADIUS session & limit history">
                        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:-1px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        History
                      </a>
                      <?php if ($canManage): ?>
                        <form method="POST" action="<?= base_url('pppoe/users/' . esc($user['id']) . '/toggle') ?>" style="display:inline;"><?= csrf_field() ?>
                          <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 8px;"><?= (int) $user['enabled'] === 1 ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <form method="POST" action="<?= base_url('pppoe/users/' . esc($user['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove subscriber <?= esc(addslashes($user['username'])) ?>?')"><?= csrf_field() ?>
                          <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 8px; color:var(--color-red);">Delete</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<!-- Server Modal -->
<div class="modal-backdrop" id="serverModal">
  <div class="modal" style="max-width:620px;">
    <div class="modal-header">
      <span id="serverModalTitle" style="font-weight:700;">Add PPPoE Server</span>
      <button type="button" onclick="closeModal('serverModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.3rem;">&times;</button>
    </div>
    <form method="POST" id="serverForm" action="<?= base_url('pppoe/servers/store') ?>">
      <?= csrf_field() ?>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" for="srvName">Server Name <span style="color:var(--color-red);">*</span></label>
          <input type="text" id="srvName" name="name" class="form-control" required placeholder="PPPoE_LAN">
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
          <div class="form-group">
            <label class="form-label" for="srvIface">Listening Interface <span style="color:var(--color-red);">*</span></label>
            <select id="srvIface" name="interface" class="form-control" required>
              <?php foreach ($interfaces as $if): ?>
                <option value="<?= esc($if['physical_device']) ?>"><?= esc($if['name']) ?> (<?= esc($if['physical_device']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="srvService">Service Name</label>
            <input type="text" id="srvService" name="service_name" class="form-control" value="MicronOS">
          </div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
          <div class="form-group">
            <label class="form-label" for="srvAcName">Access Concentrator Name</label>
            <input type="text" id="srvAcName" name="ac_name" class="form-control" placeholder="Optional">
          </div>
          <div class="form-group">
            <label class="form-label" for="srvLocal">Local Address <span style="color:var(--color-red);">*</span></label>
            <input type="text" id="srvLocal" name="local_ip" class="form-control" required placeholder="10.0.0.1" style="font-family:var(--font-mono);">
          </div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
          <div class="form-group">
            <label class="form-label" for="srvStart">Client Pool Start <span style="color:var(--color-red);">*</span></label>
            <input type="text" id="srvStart" name="remote_start" class="form-control" required placeholder="10.0.0.10" style="font-family:var(--font-mono);">
          </div>
          <div class="form-group">
            <label class="form-label" for="srvEnd">Client Pool End <span style="color:var(--color-red);">*</span></label>
            <input type="text" id="srvEnd" name="remote_end" class="form-control" required placeholder="10.0.0.250" style="font-family:var(--font-mono);">
          </div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
          <div class="form-group">
            <label class="form-label" for="srvDns1">Primary DNS</label>
            <input type="text" id="srvDns1" name="dns1" class="form-control" placeholder="Defaults to the local address" style="font-family:var(--font-mono);">
          </div>
          <div class="form-group">
            <label class="form-label" for="srvDns2">Secondary DNS</label>
            <input type="text" id="srvDns2" name="dns2" class="form-control" placeholder="Optional" style="font-family:var(--font-mono);">
          </div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
          <div class="form-group">
            <label class="form-label" for="srvIdle">Idle Timeout (s)</label>
            <input type="number" id="srvIdle" name="idle_timeout" class="form-control" value="0" min="0" max="86400">
          </div>
          <div class="form-group">
            <label class="form-label" for="srvMax">Max Sessions</label>
            <input type="number" id="srvMax" name="max_sessions" class="form-control" value="64" min="0" max="4096">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="srvDesc">Description</label>
          <input type="text" id="srvDesc" name="description" class="form-control" placeholder="Optional">
        </div>
        <div class="form-group" style="margin-bottom:14px;">
          <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
            <input type="checkbox" name="auth_required" value="1" id="srvAuth" checked style="accent-color:var(--color-cyan);">
            <span style="font-size:0.85rem;">Require CHAP authentication</span>
          </label>
        </div>

        <!-- Authentication backend -->
        <div class="form-group">
          <label class="form-label" for="srvBackend">Authentication Backend</label>
          <select name="auth_backend" id="srvBackend" class="form-control" onchange="toggleRadiusFields()">
            <option value="local">Local subscriber list (chap-secrets)</option>
            <option value="radius">RADIUS server</option>
          </select>
          <div class="form-hint">RADIUS is consulted first, so the local list cannot shadow a RADIUS decision.</div>
        </div>

        <!-- RADIUS fields -->
        <div id="radiusFields" style="display:none; padding:14px; background:var(--color-surface-3); border:1px solid var(--color-border); border-radius:var(--radius-sm); margin-bottom:16px;">
          <div style="display:grid; grid-template-columns:2fr 1fr; gap:14px;">
            <div class="form-group" style="margin-bottom:12px;">
              <label class="form-label" for="srvRadiusServer">RADIUS Server</label>
              <input type="text" id="srvRadiusServer" name="radius_server" class="form-control" placeholder="10.20.30.40" style="font-family:var(--font-mono);">
            </div>
            <div class="form-group" style="margin-bottom:12px;">
              <label class="form-label" for="srvRadiusPort">Auth Port</label>
              <input type="number" id="srvRadiusPort" name="radius_port" class="form-control" value="1812" min="1" max="65535" style="font-family:var(--font-mono);">
            </div>
          </div>
          <div class="form-group" style="margin-bottom:12px;">
            <label class="form-label" for="srvRadiusSecret">Shared Secret</label>
            <input type="password" id="srvRadiusSecret" name="radius_secret" class="form-control" autocomplete="new-password" style="font-family:var(--font-mono);">
            <div class="form-hint" id="radiusSecretHint">Leave blank to keep the stored secret.</div>
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px;">
            <div class="form-group" style="margin-bottom:12px;">
              <label class="form-label" for="srvRadiusTimeout">Timeout (s)</label>
              <input type="number" id="srvRadiusTimeout" name="radius_timeout" class="form-control" value="5" min="1" max="60" style="font-family:var(--font-mono);">
            </div>
            <div class="form-group" style="margin-bottom:12px;">
              <label class="form-label" for="srvRadiusRetries">Retries</label>
              <input type="number" id="srvRadiusRetries" name="radius_retries" class="form-control" value="3" min="0" max="10" style="font-family:var(--font-mono);">
            </div>
            <div class="form-group" style="margin-bottom:12px;">
              <label class="form-label" for="srvRadiusNasId">NAS Identifier</label>
              <input type="text" id="srvRadiusNasId" name="radius_nas_id" class="form-control" placeholder="Defaults to the server name" style="font-family:var(--font-mono);">
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="srvRadiusServer2">Secondary Server</label>
            <input type="text" id="srvRadiusServer2" name="radius_server2" class="form-control" placeholder="Optional — tried when the primary is unreachable" style="font-family:var(--font-mono);">
          </div>

          <!-- Accounting: Start/Stop are automatic once enabled; the interim
               cadence is dictated by the server, so it is not offered here. -->
          <hr style="border:none; border-top:1px solid var(--color-border); margin:14px 0;">
          <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin-bottom:12px;">
            <input type="checkbox" id="srvRadiusAccounting" name="radius_accounting" value="1" onchange="toggleAcctFields()">
            <span style="font-size:0.85rem;">Send RADIUS accounting (Acct-Start / Stop / Interim)</span>
          </label>
          <div id="acctFields" style="display:none;">
            <div style="display:grid; grid-template-columns:1fr 2fr; gap:14px;">
              <div class="form-group" style="margin-bottom:8px;">
                <label class="form-label" for="srvAcctPort">Accounting Port</label>
                <input type="number" id="srvAcctPort" name="radius_acct_port" class="form-control" value="1813" min="1" max="65535" style="font-family:var(--font-mono);">
              </div>
              <div class="form-group" style="margin-bottom:8px;">
                <label class="form-label" for="srvAcctServer">Accounting Server</label>
                <input type="text" id="srvAcctServer" name="radius_acct_server" class="form-control" placeholder="Optional — defaults to the authentication server" style="font-family:var(--font-mono);">
              </div>
            </div>
            <div class="form-hint">
              Start is sent when the session comes up and Stop when it drops. Interim updates are sent only when the RADIUS server asks for them with an Acct-Interim-Interval attribute (60s minimum), so the cadence is set on the server, not here.
            </div>
          </div>
        </div>

        <input type="hidden" name="enabled" value="1" id="srvEnabled">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('serverModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="serverSubmit">Create Server</button>
      </div>
    </form>
  </div>
</div>

<!-- RADIUS Test Modal -->
<div class="modal-backdrop" id="radiusTestModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header">
      <span style="font-weight:700;">Test RADIUS Authentication</span>
      <button type="button" onclick="closeModal('radiusTestModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.3rem;">&times;</button>
    </div>
    <form method="POST" id="radiusTestForm" action="">
      <?= csrf_field() ?>
      <div class="modal-body">
        <div style="font-size:0.78rem; color:var(--text-muted); margin-bottom:14px;">
          Sends a single Access-Request to
          <strong id="rtServer" style="color:var(--text-secondary); font-family:var(--font-mono);"></strong>
          for server <strong id="rtName" style="color:var(--text-secondary);"></strong>.
        </div>
        <div class="form-group">
          <label class="form-label" for="rtUser">Test Username <span style="color:var(--color-red);">*</span></label>
          <input type="text" id="rtUser" name="test_username" class="form-control" required style="font-family:var(--font-mono);">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="rtPass">Test Password <span style="color:var(--color-red);">*</span></label>
          <input type="password" id="rtPass" name="test_password" class="form-control" required autocomplete="new-password" style="font-family:var(--font-mono);">
          <div class="form-hint">The credential is sent to the RADIUS server for one request and not stored.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('radiusTestModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Send Test Request</button>
      </div>
    </form>
  </div>
</div>

<!-- Subscriber Modal -->
<div class="modal-backdrop" id="userModal">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <span style="font-weight:700;">Add Subscriber</span>
      <button type="button" onclick="closeModal('userModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.3rem;">&times;</button>
    </div>
    <form method="POST" id="userForm" action="<?= base_url('pppoe/users/store') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="server_id" id="usrServerId">
      <div class="modal-body">
        <div style="font-size:0.78rem; color:var(--text-muted); margin-bottom:14px;">
          Subscriber on <strong id="usrServerName" style="color:var(--text-secondary);"></strong>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
          <div class="form-group">
            <label class="form-label" for="usrName">Username <span style="color:var(--color-red);">*</span></label>
            <input type="text" id="usrName" name="username" class="form-control" required style="font-family:var(--font-mono);">
          </div>
          <div class="form-group">
            <label class="form-label" for="usrPass">Password <span style="color:var(--color-red);">*</span></label>
            <input type="password" id="usrPass" name="password" class="form-control" required autocomplete="new-password" style="font-family:var(--font-mono);">
          </div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
          <div class="form-group">
            <label class="form-label" for="usrStatic">Fixed Address</label>
            <input type="text" id="usrStatic" name="static_ip" class="form-control" placeholder="From pool" style="font-family:var(--font-mono);">
          </div>
          <div class="form-group">
            <label class="form-label" for="usrRate">Rate Limit</label>
            <input type="text" id="usrRate" name="rate_limit" class="form-control" placeholder="e.g. 10M/10M" style="font-family:var(--font-mono);">
          </div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
          <div class="form-group">
            <label class="form-label" for="usrDns1">Primary DNS</label>
            <input type="text" id="usrDns1" name="dns1" class="form-control" placeholder="Optional" style="font-family:var(--font-mono);">
          </div>
          <div class="form-group">
            <label class="form-label" for="usrDns2">Secondary DNS</label>
            <input type="text" id="usrDns2" name="dns2" class="form-control" placeholder="Optional" style="font-family:var(--font-mono);">
          </div>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="usrDesc">Description</label>
          <input type="text" id="usrDesc" name="description" class="form-control" placeholder="Optional">
        </div>
        <input type="hidden" name="enabled" value="1">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('userModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Subscriber</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Session monitor refresh ──
// Re-fetches the page and swaps in the session panel, so an operator can watch
// a dial come up without reloading the whole console.
function refreshSessions() {
  var btn = document.getElementById('sessRefresh');
  btn.disabled = true;
  btn.innerText = 'Refreshing…';

  MicronScheduler.fetch(window.location.pathname + '?partial=sessions', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) { return r.text(); })
    .then(function(html) {
      document.getElementById('sessionBody').innerHTML = html;
    })
    .catch(function() { /* leave the last good table in place */ })
    .finally(function() {
      btn.disabled = false;
      btn.innerText = 'Refresh';
    });
}

// ── Live 'Redial now' Action ──
// Immediately invokes PPPoE redial via micrond, resets escalating backoff,
// and refreshes the session panel and link-state indicator in place.
function redialNow(iface) {
  var btn = document.getElementById('btnRedialNow');
  var origHtml = btn ? btn.innerHTML : 'Redial now';
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:12px;height:12px;display:inline-block;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:spin .75s linear infinite;vertical-align:-2px;"></span> Redialing…';
  }

  var formData = new FormData();
  if (iface) {
    formData.append('interface', iface);
  }
  formData.append('force', '1');
  formData.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

  MicronScheduler.fetch('<?= base_url('pppoe/redial') ?>', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: formData
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    if (data.html) {
      document.getElementById('sessionBody').innerHTML = data.html;
    }
    if (data.linkHealth && data.linkHealth.links) {
      updateLinkStateBadges(data.linkHealth.links);
    }
    showToast(data.message || 'PPPoE redial completed.', data.success ? 'success' : 'error');
  })
  .catch(function(err) {
    showToast('Failed to redial PPPoE links. Check appliance connection.', 'error');
  })
  .finally(function() {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = origHtml;
    }
  });
}

function updateLinkStateBadges(links) {
  var container = document.getElementById('linkStateContainer');
  if (!container) return;
  var html = '';
  for (var dev in links) {
    var lh = links[dev];
    var dotClass = lh.state === 'UP' ? 'pulse-dot' : ('status-dot dot-' + (lh.state === 'BACKOFF' ? 'warning' : 'danger'));
    var extra = (lh.state === 'BACKOFF' && lh.remaining_human) ? ' (' + lh.remaining_human + ')' : '';
    html += '<span class="badge ' + lh.badge_class + '" style="display:inline-flex; align-items:center; gap:6px; font-size:0.72rem; padding:3px 8px;" id="linkBadge-' + dev + '" title="' + (lh.status_tooltip || '') + '">';
    html += '<span class="' + dotClass + '"></span> ' + (lh.name || dev) + ': ' + lh.state + extra;
    html += '</span> ';
  }
  container.innerHTML = html;
}

function showToast(message, type) {
  var toast = document.createElement('div');
  toast.className = 'animate-in';
  toast.style.cssText = 'position:fixed; bottom:24px; right:24px; z-index:9999; padding:12px 18px; border-radius:8px; font-size:0.84rem; font-weight:500; display:flex; align-items:center; gap:8px; box-shadow:0 10px 25px rgba(0,0,0,0.3); background:' + (type === 'error' ? 'var(--color-red)' : 'var(--color-green)') + '; color:#fff;';
  toast.innerHTML = (type === 'error' ? '&#9888; ' : '&#10003; ') + message;
  document.body.appendChild(toast);
  setTimeout(function() {
    toast.style.opacity = '0';
    toast.style.transition = 'opacity .4s ease';
    setTimeout(function() { toast.remove(); }, 400);
  }, 3500);
}

function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
document.querySelectorAll('.modal-backdrop').forEach(function(m) {
  m.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.active').forEach(function(m) { m.classList.remove('active'); });
});

// ── RADIUS fields visibility ──
function toggleRadiusFields() {
  var radius = document.getElementById('srvBackend').value === 'radius';
  document.getElementById('radiusFields').style.display = radius ? '' : 'none';
  toggleAcctFields();
}

// Accounting's own fields are only worth showing once accounting is on.
function toggleAcctFields() {
  var on = document.getElementById('srvRadiusAccounting').checked
        && document.getElementById('srvBackend').value === 'radius';
  document.getElementById('acctFields').style.display = on ? '' : 'none';
}

function clearRadiusFields() {
  ['srvRadiusServer','srvRadiusSecret','srvRadiusServer2','srvRadiusNasId','srvAcctServer'].forEach(function(id) {
    document.getElementById(id).value = '';
  });
  document.getElementById('srvRadiusPort').value = 1812;
  document.getElementById('srvRadiusTimeout').value = 5;
  document.getElementById('srvRadiusRetries').value = 3;
  document.getElementById('srvAcctPort').value = 1813;
  document.getElementById('srvRadiusAccounting').checked = false;
  document.getElementById('radiusSecretHint').innerText = 'Leave blank to keep the stored secret.';
  toggleAcctFields();
}

function openRadiusTest(serverId, serverName, radiusServer) {
  document.getElementById('radiusTestForm').action =
    '<?= base_url('pppoe/servers') ?>/' + encodeURIComponent(serverId) + '/test-radius';
  document.getElementById('rtName').innerText = serverName;
  document.getElementById('rtServer').innerText = radiusServer || '—';
  document.getElementById('rtUser').value = '';
  document.getElementById('rtPass').value = '';
  openModal('radiusTestModal');
}

function openServerModal() {
  document.getElementById('serverModalTitle').innerText = 'Add PPPoE Server';
  document.getElementById('serverForm').action = '<?= base_url('pppoe/servers/store') ?>';
  document.getElementById('serverSubmit').innerText = 'Create Server';
  ['srvName','srvAcName','srvLocal','srvStart','srvEnd','srvDns1','srvDns2','srvDesc'].forEach(function(id) {
    document.getElementById(id).value = '';
  });
  document.getElementById('srvService').value = 'MicronOS';
  document.getElementById('srvIdle').value = 0;
  document.getElementById('srvMax').value = 64;
  document.getElementById('srvAuth').checked = true;
  document.getElementById('srvEnabled').value = 1;
  document.getElementById('srvBackend').value = 'local';
  clearRadiusFields();
  toggleRadiusFields();
  openModal('serverModal');
}

function openEditServer(s) {
  document.getElementById('serverModalTitle').innerText = 'Edit Server: ' + s.name;
  document.getElementById('serverForm').action = '<?= base_url('pppoe/servers') ?>/' + encodeURIComponent(s.id) + '/update';
  document.getElementById('serverSubmit').innerText = 'Update Server';
  document.getElementById('srvName').value    = s.name || '';
  document.getElementById('srvIface').value   = s.interface || '';
  document.getElementById('srvService').value = s.service_name || 'MicronOS';
  document.getElementById('srvAcName').value  = s.ac_name || '';
  document.getElementById('srvLocal').value   = s.local_ip || '';
  document.getElementById('srvStart').value   = s.remote_start || '';
  document.getElementById('srvEnd').value     = s.remote_end || '';
  document.getElementById('srvDns1').value    = s.dns1 || '';
  document.getElementById('srvDns2').value    = s.dns2 || '';
  document.getElementById('srvIdle').value    = s.idle_timeout !== undefined ? s.idle_timeout : 0;
  document.getElementById('srvMax').value     = s.max_sessions !== undefined ? s.max_sessions : 64;
  document.getElementById('srvDesc').value    = s.description || '';
  document.getElementById('srvAuth').checked  = parseInt(s.auth_required) === 1;
  document.getElementById('srvEnabled').value = parseInt(s.enabled) === 1 ? 1 : 0;
  document.getElementById('srvBackend').value = s.auth_backend || 'local';
  document.getElementById('srvRadiusServer').value  = s.radius_server || '';
  document.getElementById('srvRadiusPort').value    = s.radius_port !== undefined ? s.radius_port : 1812;
  document.getElementById('srvRadiusTimeout').value = s.radius_timeout !== undefined ? s.radius_timeout : 5;
  document.getElementById('srvRadiusRetries').value = s.radius_retries !== undefined ? s.radius_retries : 3;
  document.getElementById('srvRadiusNasId').value   = s.radius_nas_id || '';
  document.getElementById('srvRadiusServer2').value = s.radius_server2 || '';
  document.getElementById('srvRadiusAccounting').checked = parseInt(s.radius_accounting) === 1;
  document.getElementById('srvAcctPort').value   = s.radius_acct_port !== undefined ? s.radius_acct_port : 1813;
  document.getElementById('srvAcctServer').value = s.radius_acct_server || '';
  // The stored secret is never sent to the browser, so the field stays blank.
  document.getElementById('srvRadiusSecret').value = '';
  document.getElementById('radiusSecretHint').innerText = s.has_radius_secret
    ? 'A secret is stored — leave blank to keep it.'
    : 'No secret stored yet — one is required for RADIUS.';
  toggleRadiusFields();
  openModal('serverModal');
}

function openUserModal(serverId, serverName) {
  document.getElementById('userForm').action = '<?= base_url('pppoe/users/store') ?>';
  document.getElementById('usrServerId').value = serverId;
  document.getElementById('usrServerName').innerText = serverName;
  ['usrName','usrPass','usrStatic','usrRate','usrDns1','usrDns2','usrDesc'].forEach(function(id) {
    document.getElementById(id).value = '';
  });
  openModal('userModal');
}
</script>

<?= $this->endSection() ?>
