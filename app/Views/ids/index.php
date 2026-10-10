<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="display:flex; align-items:center; gap:8px; font-size:0.75rem; color:var(--text-muted); margin-bottom:4px;">
        <a href="<?= base_url('dashboard') ?>" style="color:var(--text-muted); text-decoration:none;">Dashboard</a>
        <span>/</span>
        <span style="color:var(--text-primary); font-weight:600;">Security</span>
        <span>/</span>
        <span style="color:var(--text-primary); font-weight:600;">IDS & IPS</span>
      </div>
      <h1 class="page-title" style="display:flex; align-items:center; gap:10px;">
        <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        Intrusion Detection & Prevention (IDS / IPS)
      </h1>
      <p class="page-subtitle">Suricata 7+ deep packet inspection engine with IP blacklist/whitelist and GeoIP country enforcement</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <?= \App\Libraries\ServiceStatus::widget('suricata') ?>
      <?php if (session()->get('is_superadmin') || in_array('ids.apply', session()->get('permissions') ?? [])): ?>
        <form method="POST" action="<?= base_url('ids/apply') ?>" style="margin:0;">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-success" style="display:inline-flex; align-items:center; gap:6px;">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Apply Changes
          </button>
        </form>
      <?php endif; ?>

      <?php if (session()->get('is_superadmin') || in_array('ids.restart', session()->get('permissions') ?? [])): ?>
        <form method="POST" action="<?= base_url('ids/restart') ?>" style="margin:0;">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost" style="display:inline-flex; align-items:center; gap:6px;" title="Reload Suricata daemon">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Restart Engine
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($isMock ?? false): ?>
  <div class="alert alert-info animate-in" style="margin-bottom:20px; background:rgba(6,182,212,0.08); border:1px solid rgba(6,182,212,0.25); border-radius:var(--radius-md); padding:12px 16px; display:flex; align-items:center; gap:12px;">
    <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan); flex-shrink:0;">
      <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div style="font-size:0.83rem; color:var(--text-secondary);">
      Running in <strong style="color:var(--color-cyan);">Windows Simulation Mode</strong>. Live Linux packet inspection, EVE JSON events, and NFQ queue actions are simulated by the local IPC mock engine.
    </div>
  </div>
<?php endif; ?>

<!-- ── KPI Summary Cards ── -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:14px; margin-bottom:24px;" class="animate-in animate-in-delay-1">
  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-emerald);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Engine Status</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="width:10px; height:10px; border-radius:50%; background:var(--color-emerald); display:inline-block; box-shadow:0 0 8px var(--color-emerald);"></span>
      <span style="font-size:1.15rem; font-weight:700; color:var(--text-primary); text-transform:uppercase;">
        <?= strtoupper(esc($settings['mode'] ?? 'ips')) ?> Enforcing
      </span>
    </div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px; font-family:var(--font-mono);">
      <?= esc($engineStatus['engine'] ?? 'Suricata 7.0') ?>
    </div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-red);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Threat Detections</div>
    <div style="font-size:1.6rem; font-weight:700; color:var(--color-red); margin-top:2px; font-family:var(--font-mono);"><?= (int)($severityStats['total'] ?? 0) ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">
      <strong style="color:var(--color-red);"><?= (int)($severityStats['high'] ?? 0) ?></strong> Critical Sev 1 alerts
    </div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-purple);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Packets Dropped / Blocked</div>
    <div style="font-size:1.6rem; font-weight:700; color:var(--color-purple); margin-top:2px; font-family:var(--font-mono);"><?= (int)($severityStats['blocked'] ?? 0) ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">Attacks stopped at firewall wire</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-cyan);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Filtering Rules Active</div>
    <div style="font-size:1.6rem; font-weight:700; color:var(--color-cyan); margin-top:2px; font-family:var(--font-mono);">
      <?= count($whitelistRules) + count($blacklistRules) ?>
    </div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">
      <?= count($whitelistRules) ?> Whitelisted &bull; <?= count($blacklistRules) ?> Blacklisted IPs
    </div>
  </div>
</div>

<!-- ── Navigation Tabs ── -->
<div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:20px; padding:6px 12px;">
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;" role="tablist">
    <button type="button" class="btn btn-primary btn-sm ids-tab-btn active" id="tabBtn-alerts" onclick="switchIdsTab('alerts')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
      </svg>
      Threat Alerts & Events
      <span class="badge badge-purple" style="font-size:0.65rem; padding:1px 6px;"><?= count($alerts) ?></span>
    </button>

    <button type="button" class="btn btn-ghost btn-sm ids-tab-btn" id="tabBtn-iprules" onclick="switchIdsTab('iprules')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
      </svg>
      IP Whitelist & Blacklist
      <span class="badge badge-cyan" style="font-size:0.65rem; padding:1px 6px;"><?= count($whitelistRules) + count($blacklistRules) ?></span>
    </button>

    <button type="button" class="btn btn-ghost btn-sm ids-tab-btn" id="tabBtn-geoip" onclick="switchIdsTab('geoip')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      GeoIP Country Filtering
      <?php if (($settings['country_filter_mode'] ?? 'none') !== 'none'): ?>
        <span class="badge badge-emerald" style="font-size:0.65rem; padding:1px 6px;"><?= count($settings['selected_countries'] ?? []) ?></span>
      <?php endif; ?>
    </button>

    <button type="button" class="btn btn-ghost btn-sm ids-tab-btn" id="tabBtn-settings" onclick="switchIdsTab('settings')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
      </svg>
      Engine & Threat Feeds
    </button>
  </div>
</div>

<!-- ============================================================
     TAB 1: THREAT ALERTS & REAL-TIME EVENTS
============================================================ -->
<div id="tabContent-alerts" class="ids-tab-panel">
  <div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <span class="card-title">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        Suricata EVE JSON Detection Events
      </span>
      <div style="display:flex; gap:8px; align-items:center;">
        <form method="GET" action="<?= base_url('ids') ?>" style="display:flex; gap:8px; margin:0;" id="alertFilterForm">
          <input type="text" name="q" value="<?= esc($currentSearch ?? '') ?>" placeholder="Search IP, signature, country..." class="form-control" style="font-size:0.78rem; padding:6px 12px; width:210px;">
          <select name="severity" class="form-control" style="font-size:0.78rem; padding:6px 10px; width:130px;" onchange="this.form.submit()">
            <option value="">All Severities</option>
            <option value="1" <?= ($currentSev ?? '') === '1' ? 'selected' : '' ?>>High (Sev 1)</option>
            <option value="2" <?= ($currentSev ?? '') === '2' ? 'selected' : '' ?>>Medium (Sev 2)</option>
            <option value="3" <?= ($currentSev ?? '') === '3' ? 'selected' : '' ?>>Low (Sev 3)</option>
          </select>
          <button type="submit" class="btn btn-ghost btn-sm" style="padding:6px 10px;">Filter</button>
          <?php if (!empty($currentSearch) || !empty($currentSev)): ?>
            <a href="<?= base_url('ids') ?>" class="btn btn-ghost btn-sm" style="padding:6px 10px; color:var(--text-muted);">&times; Clear</a>
          <?php endif; ?>
        </form>

        <?php if (session()->get('is_superadmin') || in_array('ids.edit', session()->get('permissions') ?? [])): ?>
          <form method="POST" action="<?= base_url('ids/alerts/clear') ?>" style="margin:0;" onsubmit="return confirm('Clear all IDS alert logs?');">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost btn-sm" style="padding:6px 10px; color:var(--color-red);" title="Clear Alert History">Clear Log</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div style="overflow-x:auto;">
      <table class="iface-table">
        <thead>
          <tr>
            <th>Timestamp</th>
            <th>Source Socket</th>
            <th>Destination</th>
            <th>Attack Signature</th>
            <th>Severity</th>
            <th>Action</th>
            <th style="text-align:right;">Quick Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($alerts)): ?>
            <tr>
              <td colspan="7" style="text-align:center; padding:32px; color:var(--text-muted);">
                No security threat alerts recorded matching the criteria.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($alerts as $a): ?>
              <tr>
                <td style="font-family:var(--font-mono); font-size:0.73rem; color:var(--text-muted); white-space:nowrap;">
                  <?= esc($a['timestamp']) ?>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.78rem; white-space:nowrap;">
                  <strong style="color:var(--color-red);"><?= esc($a['src_ip']) ?></strong><?php if (!empty($a['src_port'])): ?>:<?= esc($a['src_port']) ?><?php endif; ?>
                  <?php if (!empty($a['country_code'])): ?>
                    <span class="badge badge-purple" style="font-size:0.62rem; padding:1px 5px; margin-left:4px; font-weight:700;"><?= esc($a['country_code']) ?></span>
                  <?php endif; ?>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary); white-space:nowrap;">
                  <?= esc($a['dst_ip']) ?><?php if (!empty($a['dst_port'])): ?>:<?= esc($a['dst_port']) ?><?php endif; ?>
                  <span class="badge" style="font-size:0.62rem; padding:1px 4px; background:var(--color-surface-3); color:var(--text-muted);"><?= esc($a['proto'] ?? 'TCP') ?></span>
                </td>
                <td>
                  <div style="font-weight:600; font-size:0.83rem; color:var(--text-primary);">
                    <?= esc($a['signature']) ?>
                  </div>
                  <div style="font-size:0.7rem; color:var(--text-muted);">
                    Category: <?= esc($a['category'] ?? 'Generic Threat') ?> &bull; SID: <?= esc($a['signature_id'] ?? '2000001') ?>
                  </div>
                </td>
                <td>
                  <?php if ((int)$a['severity'] === 1): ?>
                    <span class="badge badge-danger">High (Sev 1)</span>
                  <?php elseif ((int)$a['severity'] === 2): ?>
                    <span class="badge badge-warning">Medium (Sev 2)</span>
                  <?php else: ?>
                    <span class="badge badge-info">Low (Sev 3)</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (in_array(strtolower($a['action']), ['blocked', 'dropped'], true)): ?>
                    <span class="badge badge-danger"><?= esc($a['action']) ?></span>
                  <?php else: ?>
                    <span class="badge badge-warning"><?= esc($a['action']) ?></span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right; white-space:nowrap;">
                  <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:3px 8px; color:var(--color-emerald);"
                          onclick="quickIpAction('whitelist', '<?= esc($a['src_ip']) ?>', '<?= esc(addslashes($a['signature'])) ?>')"
                          title="Add Source IP to Whitelist / Passlist">
                    + Whitelist
                  </button>
                  <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:3px 8px; color:var(--color-red);"
                          onclick="quickIpAction('blacklist', '<?= esc($a['src_ip']) ?>', '<?= esc(addslashes($a['signature'])) ?>')"
                          title="Add Source IP to Blacklist / Banlist">
                    + Blacklist
                  </button>
                  <?php if (!empty($a['payload'])): ?>
                    <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:3px 6px; color:var(--text-secondary);"
                            onclick="inspectPayload('<?= esc(addslashes($a['signature'])) ?>', '<?= esc(addslashes($a['src_ip'])) ?>', '<?= esc(addslashes($a['payload'])) ?>')"
                            title="Inspect Packet Payload">
                      Payload
                    </button>
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

<!-- ============================================================
     TAB 2: IP WHITELIST & BLACKLIST RULES
============================================================ -->
<div id="tabContent-iprules" class="ids-tab-panel" style="display:none;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
    <div>
      <h2 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin:0;">IP & Subnet Exception Rules</h2>
      <p style="font-size:0.78rem; color:var(--text-muted); margin:2px 0 0 0;">Configure explicit host/CIDR exceptions to bypass detection or immediately enforce high-priority packet drops.</p>
    </div>
    <div style="display:flex; gap:10px;">
      <button type="button" class="btn btn-success btn-sm" onclick="openAddRuleModal('whitelist')">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
        </svg>
        Add Whitelist IP
      </button>
      <button type="button" class="btn btn-danger btn-sm" onclick="openAddRuleModal('blacklist')">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
        </svg>
        Add Blacklist IP
      </button>
    </div>
  </div>

  <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:24px;" class="ids-ip-grid">
    <!-- ── IP Whitelist (Passlist) Card ── -->
    <div class="card card-glass">
      <div class="card-header" style="border-left:3px solid var(--color-emerald);">
        <div>
          <span class="card-title" style="color:var(--color-emerald);">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            IP Whitelist (Passlist)
          </span>
          <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">Bypasses Suricata inspections and drops</div>
        </div>
        <span class="badge badge-emerald" style="font-size:0.7rem;"><?= count($whitelistRules) ?> Entries</span>
      </div>
      <div style="overflow-x:auto;">
        <table class="iface-table">
          <thead>
            <tr>
              <th>IP / CIDR</th>
              <th>Description</th>
              <th>Hits</th>
              <th>Status</th>
              <th style="text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($whitelistRules)): ?>
              <tr>
                <td colspan="5" style="text-align:center; padding:24px; color:var(--text-muted);">No whitelisted IPs configured.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($whitelistRules as $r): ?>
                <tr>
                  <td style="font-family:var(--font-mono); font-weight:700; color:var(--color-emerald); font-size:0.83rem;">
                    <?= esc($r['ip_address']) ?>
                  </td>
                  <td style="font-size:0.78rem; color:var(--text-secondary); max-width:200px;">
                    <?= esc($r['description'] ?: '—') ?>
                  </td>
                  <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-muted);">
                    <?= number_format((int)$r['hits']) ?>
                  </td>
                  <td>
                    <form method="POST" action="<?= base_url('ids/rules/' . esc($r['id']) . '/toggle') ?>" style="margin:0;">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" style="font-size:0.68rem; padding:2px 8px;">
                        <?= (int)$r['enabled'] === 1 ? '<span class="badge badge-emerald">Enabled</span>' : '<span class="badge" style="background:var(--color-surface-3); color:var(--text-muted);">Disabled</span>' ?>
                      </button>
                    </form>
                  </td>
                  <td style="text-align:right;">
                    <form method="POST" action="<?= base_url('ids/rules/' . esc($r['id']) . '/delete') ?>" style="margin:0;" onsubmit="return confirm('Delete whitelist rule for <?= esc($r['ip_address']) ?>?');">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-red); font-size:0.75rem; padding:2px 6px;">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── IP Blacklist (Drop Rules) Card ── -->
    <div class="card card-glass">
      <div class="card-header" style="border-left:3px solid var(--color-red);">
        <div>
          <span class="card-title" style="color:var(--color-red);">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
            </svg>
            IP Blacklist (Blocklist)
          </span>
          <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">Immediate priority-1 drop on all matching packets</div>
        </div>
        <span class="badge badge-danger" style="font-size:0.7rem;"><?= count($blacklistRules) ?> Entries</span>
      </div>
      <div style="overflow-x:auto;">
        <table class="iface-table">
          <thead>
            <tr>
              <th>IP / CIDR</th>
              <th>Description</th>
              <th>Hits</th>
              <th>Status</th>
              <th style="text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($blacklistRules)): ?>
              <tr>
                <td colspan="5" style="text-align:center; padding:24px; color:var(--text-muted);">No blacklisted IPs configured.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($blacklistRules as $r): ?>
                <tr>
                  <td style="font-family:var(--font-mono); font-weight:700; color:var(--color-red); font-size:0.83rem;">
                    <?= esc($r['ip_address']) ?>
                  </td>
                  <td style="font-size:0.78rem; color:var(--text-secondary); max-width:200px;">
                    <?= esc($r['description'] ?: '—') ?>
                  </td>
                  <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-muted);">
                    <?= number_format((int)$r['hits']) ?>
                  </td>
                  <td>
                    <form method="POST" action="<?= base_url('ids/rules/' . esc($r['id']) . '/toggle') ?>" style="margin:0;">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" style="font-size:0.68rem; padding:2px 8px;">
                        <?= (int)$r['enabled'] === 1 ? '<span class="badge badge-danger">Active Drop</span>' : '<span class="badge" style="background:var(--color-surface-3); color:var(--text-muted);">Disabled</span>' ?>
                      </button>
                    </form>
                  </td>
                  <td style="text-align:right;">
                    <form method="POST" action="<?= base_url('ids/rules/' . esc($r['id']) . '/delete') ?>" style="margin:0;" onsubmit="return confirm('Delete blacklist rule for <?= esc($r['ip_address']) ?>?');">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-red); font-size:0.75rem; padding:2px 6px;">Delete</button>
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
</div>

<!-- ============================================================
     TAB 3: GEOIP COUNTRY FILTERING (BLACK & WHITE LISTING)
============================================================ -->
<div id="tabContent-geoip" class="ids-tab-panel" style="display:none;">
  <form method="POST" action="<?= base_url('ids/country/save') ?>">
    <?= csrf_field() ?>

    <div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:24px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          GeoIP Country-Level Filtering & Geoblocking
        </span>
        <span class="badge badge-purple">Suricata GeoIP Rules</span>
      </div>
      <div class="card-body" style="padding:22px;">

        <!-- Filter Mode Choice Cards -->
        <label class="form-label" style="margin-bottom:8px; font-weight:700;">Country Enforcement Policy</label>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:20px;">
          <!-- Disabled -->
          <label class="iface-type-card <?= ($settings['country_filter_mode'] ?? 'none') === 'none' ? 'active' : '' ?>" id="geoModeCard-none" onclick="selectGeoMode('none')">
            <input type="radio" name="country_filter_mode" value="none" <?= ($settings['country_filter_mode'] ?? 'none') === 'none' ? 'checked' : '' ?> style="display:none;">
            <div class="iface-type-radio"><div class="iface-type-radio-dot"></div></div>
            <div class="iface-type-body">
              <div class="iface-type-title">Disabled</div>
              <div class="iface-type-desc">No geographic country filtering. Packet evaluation relies on standard threat rules.</div>
            </div>
          </label>

          <!-- Blacklist (Geoblocking) -->
          <label class="iface-type-card <?= ($settings['country_filter_mode'] ?? '') === 'blacklist' ? 'active' : '' ?>" id="geoModeCard-blacklist" onclick="selectGeoMode('blacklist')">
            <input type="radio" name="country_filter_mode" value="blacklist" <?= ($settings['country_filter_mode'] ?? '') === 'blacklist' ? 'checked' : '' ?> style="display:none;">
            <div class="iface-type-radio"><div class="iface-type-radio-dot"></div></div>
            <div class="iface-type-body">
              <div class="iface-type-title" style="color:var(--color-red);">Country Blacklist (Geo-Blocking)</div>
              <div class="iface-type-desc">Drop or alert on all traffic originating from or destined to the selected countries.</div>
            </div>
          </label>

          <!-- Whitelist (Geofencing) -->
          <label class="iface-type-card <?= ($settings['country_filter_mode'] ?? '') === 'whitelist' ? 'active' : '' ?>" id="geoModeCard-whitelist" onclick="selectGeoMode('whitelist')">
            <input type="radio" name="country_filter_mode" value="whitelist" <?= ($settings['country_filter_mode'] ?? '') === 'whitelist' ? 'checked' : '' ?> style="display:none;">
            <div class="iface-type-radio"><div class="iface-type-radio-dot"></div></div>
            <div class="iface-type-body">
              <div class="iface-type-title" style="color:var(--color-emerald);">Country Whitelist (Geo-Fencing)</div>
              <div class="iface-type-desc">Permit only selected countries; all foreign unlisted nation traffic triggers enforcement action.</div>
            </div>
          </label>
        </div>

        <!-- Action on Match -->
        <div class="form-group" style="margin-bottom:20px; max-width:440px;">
          <label class="form-label" for="country_filter_action">Action on Country Policy Match</label>
          <select id="country_filter_action" name="country_filter_action" class="form-control">
            <option value="drop" <?= ($settings['country_filter_action'] ?? 'drop') === 'drop' ? 'selected' : '' ?>>Drop Packet (Silent Wire Discard)</option>
            <option value="alert" <?= ($settings['country_filter_action'] ?? '') === 'alert' ? 'selected' : '' ?>>Alert Only (Log event to EVE feed without dropping)</option>
            <option value="reject" <?= ($settings['country_filter_action'] ?? '') === 'reject' ? 'selected' : '' ?>>Reject (Send TCP Reset / ICMP Unreachable)</option>
          </select>
          <div class="form-hint">Specifies Suricata's verdict rule prefix when traffic matches the country set.</div>
        </div>

        <!-- Country Multi-Select Container -->
        <div style="border-top:1px solid var(--color-border); padding-top:18px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
            <div>
              <label class="form-label" style="margin-bottom:2px; font-weight:700;">Selected Nations Catalogue</label>
              <div style="font-size:0.75rem; color:var(--text-muted);">
                Currently selected: <strong id="countrySelectedCount" style="color:var(--color-cyan);"><?= count($settings['selected_countries'] ?? []) ?></strong> nations
              </div>
            </div>

            <!-- Quick Presets -->
            <div style="display:flex; gap:8px;">
              <input type="text" id="countrySearchInput" placeholder="Filter countries..." oninput="filterCountriesList(this.value)" class="form-control" style="font-size:0.75rem; padding:4px 10px; width:180px;">
              <button type="button" class="btn btn-ghost btn-sm" onclick="selectHighRiskCountries()" style="font-size:0.72rem; padding:4px 10px;">+ High-Risk Threat Nations</button>
              <button type="button" class="btn btn-ghost btn-sm" onclick="clearAllCountries()" style="font-size:0.72rem; padding:4px 10px;">Clear All</button>
            </div>
          </div>

          <!-- Country List Grid with Scroll -->
          <div style="max-height:340px; overflow-y:auto; border:1px solid var(--color-border); border-radius:var(--radius-md); padding:14px; background:var(--color-surface-2);" id="countriesGrid">
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:10px;">
              <?php
                $selectedList = array_map('strtoupper', $settings['selected_countries'] ?? []);
                foreach ($countries as $code => $countryName):
                  $isChecked = in_array(strtoupper($code), $selectedList, true);
              ?>
                <label class="country-checkbox-item" data-code="<?= esc(strtoupper($code)) ?>" data-name="<?= esc(strtolower($countryName)) ?>"
                       style="display:flex; align-items:center; gap:8px; padding:6px 10px; background:var(--color-surface-1); border:1px solid <?= $isChecked ? 'var(--color-cyan)' : 'var(--color-border)' ?>; border-radius:var(--radius-sm); cursor:pointer; font-size:0.78rem; transition:all var(--transition-fast);">
                  <input type="checkbox" name="selected_countries[]" value="<?= esc($code) ?>" <?= $isChecked ? 'checked' : '' ?>
                         onchange="updateCountryItemState(this)" style="accent-color:var(--color-cyan); cursor:pointer;">
                  <span class="badge" style="font-family:var(--font-mono); font-size:0.68rem; padding:2px 5px; background:var(--color-surface-3); font-weight:700; color:var(--text-secondary);">
                    <?= esc($code) ?>
                  </span>
                  <span class="country-label" style="color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= esc($countryName) ?>">
                    <?= esc($countryName) ?>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

      </div>
      <div class="card-footer" style="display:flex; justify-content:flex-end; gap:10px; padding:14px 22px; border-top:1px solid var(--color-border);">
        <button type="submit" class="btn btn-primary" style="min-width:140px;">Save GeoIP Settings</button>
      </div>
    </div>
  </form>
</div>

<!-- ============================================================
     TAB 4: ENGINE & THREAT INTELLIGENCE SETTINGS
============================================================ -->
<div id="tabContent-settings" class="ids-tab-panel" style="display:none;">
  <form method="POST" action="<?= base_url('ids/settings/save') ?>">
    <?= csrf_field() ?>

    <div class="card card-glass animate-in animate-in-delay-2" style="max-width:880px; margin-bottom:24px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
          </svg>
          Suricata Engine Operational Parameters
        </span>
      </div>
      <div class="card-body" style="padding:22px;">

        <!-- Enable IDS Switch -->
        <div class="form-group" style="padding:14px 18px; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); margin-bottom:20px;">
          <label style="display:flex; align-items:center; gap:12px; cursor:pointer; margin:0;">
            <input type="checkbox" name="enabled" value="1" <?= ((int)($settings['enabled'] ?? 1) === 1) ? 'checked' : '' ?>
                   style="accent-color:var(--color-emerald); width:18px; height:18px; cursor:pointer;">
            <div>
              <div style="font-weight:600; font-size:0.9rem; color:var(--text-primary);">Enable Intrusion Detection & Prevention Engine</div>
              <div style="font-size:0.75rem; color:var(--text-muted);">Activate multi-threaded deep packet inspection (DPI) and threat detection.</div>
            </div>
          </label>
        </div>

        <!-- Mode Choice -->
        <div class="form-group" style="margin-bottom:20px;">
          <label class="form-label" style="font-weight:700;">Operational Mode</label>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
            <label class="iface-type-card <?= ($settings['mode'] ?? 'ips') === 'ips' ? 'active' : '' ?>" id="modeCard-ips" onclick="selectEngineMode('ips')">
              <input type="radio" name="mode" value="ips" <?= ($settings['mode'] ?? 'ips') === 'ips' ? 'checked' : '' ?> style="display:none;">
              <div class="iface-type-radio"><div class="iface-type-radio-dot"></div></div>
              <div class="iface-type-body">
                <div class="iface-type-title" style="color:var(--color-cyan);">Inline IPS (Active Threat Prevention)</div>
                <div class="iface-type-desc">Intercepts packets in-line via Netfilter Queue (NFQ). Malicious packets are dropped before passing the network.</div>
              </div>
            </label>

            <label class="iface-type-card <?= ($settings['mode'] ?? '') === 'ids' ? 'active' : '' ?>" id="modeCard-ids" onclick="selectEngineMode('ids')">
              <input type="radio" name="mode" value="ids" <?= ($settings['mode'] ?? '') === 'ids' ? 'checked' : '' ?> style="display:none;">
              <div class="iface-type-radio"><div class="iface-type-radio-dot"></div></div>
              <div class="iface-type-body">
                <div class="iface-type-title">Passive IDS (Detection & Alerting Only)</div>
                <div class="iface-type-desc">Sniffs mirrored traffic via AF_PACKET rings without latency. Alerts are recorded without blocking packets.</div>
              </div>
            </label>
          </div>
        </div>

        <!-- Monitored Interfaces -->
        <div class="form-group" style="margin-bottom:20px;">
          <label class="form-label" style="font-weight:700;">Monitored Network Interfaces</label>
          <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:6px;">
            <?php
              $selectedIfaces = $settings['interfaces'] ?? ['wan'];
              foreach ($interfaces as $iface):
                $isIfaceChecked = in_array($iface['id'], $selectedIfaces, true);
            ?>
              <label style="display:inline-flex; align-items:center; gap:8px; padding:8px 14px; background:var(--color-surface-2); border:1px solid <?= $isIfaceChecked ? 'var(--color-cyan)' : 'var(--color-border)' ?>; border-radius:var(--radius-md); cursor:pointer; font-size:0.82rem;">
                <input type="checkbox" name="interfaces[]" value="<?= esc($iface['id']) ?>" <?= $isIfaceChecked ? 'checked' : '' ?> style="accent-color:var(--color-cyan); cursor:pointer;">
                <span style="font-weight:700; color:var(--text-primary);"><?= esc($iface['name']) ?></span>
                <span style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-muted);">(<?= esc($iface['physical_device']) ?>)</span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- NFQUEUE / Inline IPS Diversion -->
        <div class="form-group" style="margin-bottom:20px;">
          <label class="form-label" style="font-weight:700;">Inline IPS Packet Diversion (Netfilter Queue)</label>
          <p style="font-size:0.72rem; color:var(--text-muted); margin:4px 0 10px;">
            Applied only when the engine runs in Inline IPS mode. Suricata reads the configured queue; the firewall ruleset diverts
            accepted traffic into it (<code>queue num N</code>) and drops nothing before the filter chains have run.
          </p>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
            <div>
              <label class="form-label" style="font-size:0.8rem; font-weight:600;">Netfilter Queue Number</label>
              <input type="number" name="nfqueue_num" min="0" max="65535" value="<?= (int) ($settings['nfqueue_num'] ?? 0) ?>" class="form-control">
              <small style="font-size:0.7rem; color:var(--text-muted);">Must match the queue Suricata's <code>nfq:</code> section reads (default 0).</small>
            </div>
            <div>
              <label class="form-label" style="font-size:0.8rem; font-weight:600;">If the Engine is Unavailable</label>
              <select name="queue_fail_mode" class="form-control">
                <option value="open" <?= ($settings['queue_fail_mode'] ?? 'open') === 'open' ? 'selected' : '' ?>>Fail open — forward uninspected (adds bypass)</option>
                <option value="closed" <?= ($settings['queue_fail_mode'] ?? '') === 'closed' ? 'selected' : '' ?>>Fail closed — drop until the engine returns</option>
              </select>
              <small style="font-size:0.7rem; color:var(--text-muted);">Fail open is strongly recommended: a crashed scanner must never blackhole the network.</small>
            </div>
          </div>
        </div>

        <!-- Threat Intelligence Rule Feeds -->
        <div class="form-group" style="margin-bottom:20px;">
          <label class="form-label" style="font-weight:700;">Threat Intelligence Feeds</label>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:6px;">
            <?php
              $feeds = [
                'et_open'            => ['Emerging Threats (ET) Open Rules', 'Daily updated community signatures for malware C2, exploits, and scans'],
                'abuse_ch_c2'        => ['Abuse.ch Feodo & SSL Blacklist', 'High-confidence botnet C2 IP feeds and malicious SSL certificates'],
                'suricata_app_layer' => ['Suricata Native Protocol Decoders', 'Anomaly detection for HTTP, TLS, DNS, SSH, and SMTP streams'],
                'pt_research'        => ['Positive Technologies Threat Feeds', 'Critical vulnerability signatures (CVEs, Web shell execution, RCE)'],
              ];
              $enabledFeeds = $settings['rule_sources'] ?? ['et_open', 'abuse_ch_c2', 'suricata_app_layer'];
              foreach ($feeds as $feedKey => [$feedTitle, $feedDesc]):
                $isFeedChecked = in_array($feedKey, $enabledFeeds, true);
            ?>
              <label style="display:flex; align-items:flex-start; gap:10px; padding:12px; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); cursor:pointer;">
                <input type="checkbox" name="rule_sources[]" value="<?= esc($feedKey) ?>" <?= $isFeedChecked ? 'checked' : '' ?> style="accent-color:var(--color-cyan); margin-top:3px; cursor:pointer;">
                <div>
                  <div style="font-size:0.83rem; font-weight:600; color:var(--text-primary);"><?= esc($feedTitle) ?></div>
                  <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;"><?= esc($feedDesc) ?></div>
                </div>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Logging Options -->
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" style="font-weight:700;">Logging & Diagnostics Options</label>
          <div style="display:flex; flex-direction:column; gap:8px;">
            <label style="display:inline-flex; align-items:center; gap:10px; cursor:pointer; font-size:0.82rem; color:var(--text-primary);">
              <input type="checkbox" name="log_payloads" value="1" <?= ((int)($settings['log_payloads'] ?? 1) === 1) ? 'checked' : '' ?> style="accent-color:var(--color-cyan); cursor:pointer;">
              Log complete HTTP/TLS and packet payloads in EVE JSON feed
            </label>
            <label style="display:inline-flex; align-items:center; gap:10px; cursor:pointer; font-size:0.82rem; color:var(--text-primary);">
              <input type="checkbox" name="auto_update" value="1" <?= ((int)($settings['auto_update'] ?? 1) === 1) ? 'checked' : '' ?> style="accent-color:var(--color-cyan); cursor:pointer;">
              Daily automatic threat rule update and hot-reload via cron
            </label>
          </div>
        </div>

      </div>
      <div class="card-footer" style="display:flex; justify-content:flex-end; gap:10px; padding:14px 22px; border-top:1px solid var(--color-border);">
        <button type="submit" class="btn btn-primary" style="min-width:140px;">Save Engine Settings</button>
      </div>
    </div>
  </form>
</div>

<!-- ============================================================
     MODAL: ADD IP RULE (WHITELIST OR BLACKLIST)
============================================================ -->
<div id="addRuleModal" class="iface-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="addRuleModalTitle">
  <div class="iface-modal-dialog sm">
    <div class="iface-modal-header">
      <h2 class="iface-modal-title" id="addRuleModalTitle">Add IP Rule</h2>
      <button type="button" class="iface-modal-close" onclick="closeAddRuleModal()" aria-label="Close dialog">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>
    <form method="POST" action="<?= base_url('ids/rules/store') ?>" class="iface-modal-form">
      <?= csrf_field() ?>
      <div class="iface-modal-body" style="padding:22px;">

        <div class="form-group" style="margin-bottom:16px;">
          <label class="form-label" for="rule_list_type">List Type</label>
          <select id="rule_list_type" name="list_type" class="form-control" onchange="updateRuleModalStyling(this.value)">
            <option value="whitelist">IP Whitelist (Passlist — Bypass packet inspection)</option>
            <option value="blacklist" selected>IP Blacklist (Blocklist — Instant packet drop)</option>
          </select>
        </div>

        <div class="form-group" style="margin-bottom:16px;">
          <label class="form-label" for="rule_ip_address">IP Address or CIDR Subnet <span style="color:var(--color-red);">*</span></label>
          <input type="text" id="rule_ip_address" name="ip_address" class="form-control font-mono" placeholder="e.g. 198.51.100.44 or 10.10.0.0/16" required autocomplete="off" spellcheck="false">
          <div class="form-hint">IPv4 or IPv6 single address or CIDR network block.</div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="rule_description">Description / Threat Rationale</label>
          <input type="text" id="rule_description" name="description" class="form-control" placeholder="e.g. C2 Botnet IP or Trusted Jump Host" autocomplete="off">
        </div>

      </div>
      <div class="iface-modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeAddRuleModal()">Cancel</button>
        <button type="submit" id="ruleSubmitBtn" class="btn btn-danger">Save Rule</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     MODAL: INSPECT PACKET PAYLOAD
============================================================ -->
<div id="payloadModal" class="iface-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="payloadModalTitle">
  <div class="iface-modal-dialog">
    <div class="iface-modal-header">
      <h2 class="iface-modal-title" id="payloadModalTitle">Threat Alert Payload</h2>
      <button type="button" class="iface-modal-close" onclick="closePayloadModal()" aria-label="Close dialog">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>
    <div class="iface-modal-body" style="padding:22px;">
      <div style="font-size:0.83rem; font-weight:700; color:var(--text-primary); margin-bottom:4px;" id="payloadModalSig"></div>
      <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:14px;" id="payloadModalIp"></div>
      <pre id="payloadModalContent" style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); padding:16px; font-family:var(--font-mono); font-size:0.8rem; color:var(--color-cyan); white-space:pre-wrap; word-break:break-all; max-height:300px; overflow-y:auto;"></pre>
    </div>
    <div class="iface-modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closePayloadModal()">Close</button>
    </div>
  </div>
</div>

<script>
// ── Tab Management with Hash Support ──
function switchIdsTab(tabId) {
  document.querySelectorAll('.ids-tab-panel').forEach(p => p.style.display = 'none');
  document.querySelectorAll('.ids-tab-btn').forEach(b => {
    b.classList.remove('active');
    b.classList.remove('btn-primary');
    b.classList.add('btn-ghost');
  });

  const targetPanel = document.getElementById('tabContent-' + tabId);
  const targetBtn   = document.getElementById('tabBtn-' + tabId);

  if (targetPanel && targetBtn) {
    targetPanel.style.display = 'block';
    targetBtn.classList.add('active');
    targetBtn.classList.add('btn-primary');
    targetBtn.classList.remove('btn-ghost');
    window.location.hash = tabId;
  }
}

// Check initial hash on load
window.addEventListener('DOMContentLoaded', () => {
  const hash = window.location.hash.replace('#', '');
  if (['alerts', 'iprules', 'geoip', 'settings'].includes(hash)) {
    switchIdsTab(hash);
  }
});

// ── Modals & Quick Actions ──
function openAddRuleModal(defaultType) {
  const typeSelect = document.getElementById('rule_list_type');
  if (typeSelect) {
    typeSelect.value = defaultType || 'blacklist';
    updateRuleModalStyling(typeSelect.value);
  }
  const modal = document.getElementById('addRuleModal');
  modal.classList.add('active');
  setTimeout(() => document.getElementById('rule_ip_address')?.focus(), 50);
}

function closeAddRuleModal() {
  document.getElementById('addRuleModal').classList.remove('active');
}

function updateRuleModalStyling(type) {
  const btn = document.getElementById('ruleSubmitBtn');
  const title = document.getElementById('addRuleModalTitle');
  if (type === 'whitelist') {
    btn.className = 'btn btn-success';
    btn.textContent = 'Save Whitelist Rule';
    title.textContent = 'Add IP to Whitelist (Passlist)';
  } else {
    btn.className = 'btn btn-danger';
    btn.textContent = 'Save Blacklist Rule';
    title.textContent = 'Add IP to Blacklist (Blocklist)';
  }
}

function quickIpAction(action, ip, signature) {
  const label = action === 'whitelist' ? 'WHITELIST' : 'BLACKLIST';
  if (!confirm(`Add IP ${ip} to ${label}?`)) return;

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = '<?= base_url('ids/quick-action') ?>';

  const csrf = document.createElement('input');
  csrf.type = 'hidden';
  csrf.name = '<?= csrf_token() ?>';
  csrf.value = '<?= csrf_hash() ?>';
  form.appendChild(csrf);

  const actInput = document.createElement('input');
  actInput.type = 'hidden';
  actInput.name = 'action';
  actInput.value = action;
  form.appendChild(actInput);

  const ipInput = document.createElement('input');
  ipInput.type = 'hidden';
  ipInput.name = 'ip';
  ipInput.value = ip;
  form.appendChild(ipInput);

  const sigInput = document.createElement('input');
  sigInput.type = 'hidden';
  sigInput.name = 'signature';
  sigInput.value = signature;
  form.appendChild(sigInput);

  document.body.appendChild(form);
  form.submit();
}

function inspectPayload(sig, ip, payload) {
  document.getElementById('payloadModalSig').textContent = sig;
  document.getElementById('payloadModalIp').textContent = 'Source Socket: ' + ip;
  document.getElementById('payloadModalContent').textContent = payload;
  document.getElementById('payloadModal').classList.add('active');
}

function closePayloadModal() {
  document.getElementById('payloadModal').classList.remove('active');
}

// ── GeoIP Country Selection Helpers ──
function selectGeoMode(mode) {
  document.querySelectorAll('[id^="geoModeCard-"]').forEach(c => c.classList.remove('active'));
  const card = document.getElementById('geoModeCard-' + mode);
  if (card) {
    card.classList.add('active');
    const radio = card.querySelector('input[type="radio"]');
    if (radio) radio.checked = true;
  }
}

function selectEngineMode(mode) {
  document.querySelectorAll('[id^="modeCard-"]').forEach(c => c.classList.remove('active'));
  const card = document.getElementById('modeCard-' + mode);
  if (card) {
    card.classList.add('active');
    const radio = card.querySelector('input[type="radio"]');
    if (radio) radio.checked = true;
  }
}

function filterCountriesList(query) {
  const q = query.toLowerCase().trim();
  document.querySelectorAll('.country-checkbox-item').forEach(item => {
    const code = item.getAttribute('data-code').toLowerCase();
    const name = item.getAttribute('data-name').toLowerCase();
    if (q === '' || code.includes(q) || name.includes(q)) {
      item.style.display = 'flex';
    } else {
      item.style.display = 'none';
    }
  });
}

function updateCountryItemState(checkbox) {
  const item = checkbox.closest('.country-checkbox-item');
  if (checkbox.checked) {
    item.style.borderColor = 'var(--color-cyan)';
  } else {
    item.style.borderColor = 'var(--color-border)';
  }
  updateSelectedCountryCounter();
}

function updateSelectedCountryCounter() {
  const count = document.querySelectorAll('.country-checkbox-item input[type="checkbox"]:checked').length;
  document.getElementById('countrySelectedCount').textContent = count;
}

function selectHighRiskCountries() {
  const highRisk = ['RU', 'CN', 'KP', 'IR', 'BY'];
  document.querySelectorAll('.country-checkbox-item').forEach(item => {
    const code = item.getAttribute('data-code');
    const cb = item.querySelector('input[type="checkbox"]');
    if (highRisk.includes(code)) {
      cb.checked = true;
      item.style.borderColor = 'var(--color-cyan)';
    }
  });
  updateSelectedCountryCounter();
}

function clearAllCountries() {
  document.querySelectorAll('.country-checkbox-item').forEach(item => {
    const cb = item.querySelector('input[type="checkbox"]');
    cb.checked = false;
    item.style.borderColor = 'var(--color-border)';
  });
  updateSelectedCountryCounter();
}

// Close modals on backdrop click or Escape
window.addEventListener('click', (e) => {
  const addModal = document.getElementById('addRuleModal');
  if (e.target === addModal) closeAddRuleModal();

  const payModal = document.getElementById('payloadModal');
  if (e.target === payModal) closePayloadModal();
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closeAddRuleModal();
    closePayloadModal();
  }
});
</script>

<style>
@media (max-width: 900px) {
  .ids-ip-grid {
    grid-template-columns: 1fr !important;
  }
}
</style>

<?= $this->endSection() ?>
