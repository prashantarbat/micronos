<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <span>Routing</span>
      <span class="sep">/</span>
      <span class="current">Application-Aware SD-WAN</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-indigo">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6M3 17l4-4 4 4 6-6"/>
        </svg>
      </div>
      Application-Aware Dynamic SD-WAN (SLA Path Steering)
    </h1>
    <p class="page-subtitle">Autonomous multi-WAN health probing (Loss, Latency RTT, RFC 3550 Jitter) with application-level dynamic path steering and hitless failover across diverse uplinks.</p>
  </div>
  <div class="page-header-actions">
    <button type="button" class="btn btn-ghost" onclick="runProbeNow()" id="probeBtn" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
      </svg>
      Probe All Uplinks Now
    </button>

    <button type="button" class="btn btn-primary" onclick="openRuleModal()" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
      </svg>
      New Steering Rule
    </button>

    <button type="button" onclick="applySdwanPolicies()" class="btn btn-success" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
      </svg>
      Apply &amp; Reload Routing
    </button>
  </div>
</div>

<!-- ── KPI Summary Cards ── -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:24px;" class="animate-in animate-in-delay-1">
  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-emerald);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">SD-WAN Controller</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="width:10px; height:10px; border-radius:50%; background:var(--color-emerald); display:inline-block; box-shadow:0 0 8px var(--color-emerald);"></span>
      <span style="font-size:1.15rem; font-weight:700; color:var(--text-primary);">
        <?= ($settings['enabled'] ?? 1) ? 'ACTIVE / STEERING' : 'DISABLED' ?>
      </span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Kernel PBR &amp; nDPI Mark Sync</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-indigo, #6366f1);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Monitored Uplinks</div>
    <div style="font-size:1.35rem; font-weight:800; color:var(--text-primary); margin-top:4px;">
      <?= count($members) ?> WAN Interfaces
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Fiber, Broadband, 5G/LTE</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-amber, #f59e0b);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Performance SLA Profiles</div>
    <div style="font-size:1.35rem; font-weight:800; color:var(--text-primary); margin-top:4px;">
      <?= count($profiles) ?> Active Profiles
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">VoIP, Cloud ERP, Bulk Web</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-cyan, #06b6d4);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Steering Rules</div>
    <div style="font-size:1.35rem; font-weight:800; color:var(--text-primary); margin-top:4px;">
      <?= count($rules) ?> Ordered Rules
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Dynamic SLA Path Resolution</div>
  </div>
</div>

<!-- ── Tab Navigation ── -->
<div class="tab-bar animate-in animate-in-delay-2" style="margin-bottom:20px;">
  <button type="button" class="tab-btn tab-item active" onclick="switchSdwanTab('matrix', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
    WAN Health &amp; SLA Matrix
  </button>
  <button type="button" class="tab-btn tab-item" onclick="switchSdwanTab('profiles', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
    Performance SLA Profiles
  </button>
  <button type="button" class="tab-btn tab-item" onclick="switchSdwanTab('rules', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
    Path Steering Rules
  </button>
  <button type="button" class="tab-btn tab-item" onclick="switchSdwanTab('members', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
    WAN Uplink Members
  </button>
  <button type="button" class="tab-btn tab-item" onclick="switchSdwanTab('settings', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/></svg>
    Probing &amp; Engine Settings
  </button>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1: WAN HEALTH & SLA MATRIX                                          -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="sdwan-tab-matrix" class="tab-pane active animate-in">
  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:18px; margin-bottom:24px;">
    <?php foreach ($members as $m): ?>
      <div class="card card-glass" style="padding:20px; border-top:4px solid <?= $m['status'] === 'alive' ? 'var(--color-emerald)' : ($m['status'] === 'degraded' ? 'var(--color-amber)' : 'var(--color-rose)') ?>;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
          <div>
            <div style="font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); font-weight:700; font-family:monospace;">
              <?= esc($m['interface']) ?> &bull; GW: <?= esc($m['gateway_ip']) ?>
            </div>
            <h3 style="margin:4px 0 0; font-size:1.05rem; font-weight:700; color:var(--text-primary);">
              <?= esc($m['name']) ?>
            </h3>
          </div>
          <?php if ($m['status'] === 'alive'): ?>
            <span class="badge badge-emerald" style="font-size:0.75rem;">SLA HEALTHY</span>
          <?php elseif ($m['status'] === 'degraded'): ?>
            <span class="badge badge-amber" style="font-size:0.75rem;">DEGRADED</span>
          <?php else: ?>
            <span class="badge badge-rose" style="font-size:0.75rem;">OFFLINE</span>
          <?php endif; ?>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; background:rgba(0,0,0,0.25); padding:12px; border-radius:8px; margin-bottom:14px; text-align:center;">
          <div>
            <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Packet Loss</div>
            <div style="font-size:1.15rem; font-weight:800; font-family:monospace; color:<?= (float)$m['current_loss'] > 1.0 ? 'var(--color-rose)' : 'var(--text-primary)' ?>;">
              <?= number_format((float)$m['current_loss'], 2) ?>%
            </div>
          </div>
          <div>
            <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Latency (RTT)</div>
            <div style="font-size:1.15rem; font-weight:800; font-family:monospace; color:<?= (float)$m['current_latency'] > 100.0 ? 'var(--color-amber)' : 'var(--color-cyan)' ?>;">
              <?= number_format((float)$m['current_latency'], 1) ?> ms
            </div>
          </div>
          <div>
            <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Jitter</div>
            <div style="font-size:1.15rem; font-weight:800; font-family:monospace; color:<?= (float)$m['current_jitter'] > 15.0 ? 'var(--color-rose)' : 'var(--text-primary)' ?>;">
              <?= number_format((float)$m['current_jitter'], 1) ?> ms
            </div>
          </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; color:var(--text-secondary);">
          <span>Probe Target: <code style="color:var(--text-primary);"><?= esc($m['ping_target']) ?></code></span>
          <span>Routing Cost: <strong><?= esc($m['cost']) ?></strong> &bull; Weight: <strong><?= esc($m['weight']) ?></strong></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 2: PERFORMANCE SLA PROFILES                                        -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="sdwan-tab-profiles" class="tab-pane animate-in" style="display:none;">
  <div class="card card-glass" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--border-color);">
      <div>
        <h3 style="margin:0; font-size:1rem; font-weight:700; color:var(--text-primary);">Configured SD-WAN SLA Performance Profiles</h3>
        <p style="margin:4px 0 0; font-size:0.78rem; color:var(--text-muted);">SLA profiles enforce upper bounds on packet loss, round-trip latency, and jitter. When an uplink violates thresholds, traffic automatically steers to backup paths.</p>
      </div>
      <button type="button" class="btn btn-sm btn-primary" onclick="openProfileModal()">+ Add SLA Profile</button>
    </div>
    <div class="table-responsive">
      <table class="table" style="margin:0; width:100%; border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color); background:var(--bg-card-header, rgba(255,255,255,0.02));">
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Profile Name</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">SLA Criteria</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Preferred / Backup</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Assigned Applications</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:center;">Dynamic Path Decision</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($profiles as $p): ?>
            <?php
              $apps = json_decode($p['assigned_applications'] ?? '[]', true) ?: [];
              $decision = $steeringDecisions[$p['id']] ?? null;
              $selectedMem = $decision['selected_member'] ?? null;
            ?>
            <tr style="border-bottom:1px solid var(--border-color);">
              <td style="padding:14px 18px;">
                <div style="font-weight:700; color:var(--text-primary);"><?= esc($p['name']) ?></div>
                <div style="font-size:0.75rem; color:var(--text-muted);"><?= esc($p['description'] ?: 'No description') ?></div>
              </td>
              <td style="padding:14px 18px; font-family:monospace; font-size:0.8rem;">
                <div>Loss: &le; <strong><?= esc($p['sla_max_loss_pct']) ?>%</strong></div>
                <div>RTT: &le; <strong><?= esc($p['sla_max_latency_ms']) ?> ms</strong></div>
                <div>Jitter: &le; <strong><?= esc($p['sla_max_jitter_ms']) ?> ms</strong></div>
              </td>
              <td style="padding:14px 18px; font-size:0.8rem;">
                <div>Preferred: <span style="font-weight:600; color:var(--color-indigo);"><?= esc($p['preferred_member_id'] ?: 'None') ?></span></div>
                <div>Backup: <span style="color:var(--text-secondary);"><?= esc($p['backup_member_id'] ?: 'None') ?></span></div>
              </td>
              <td style="padding:14px 18px;">
                <div style="display:flex; flex-wrap:wrap; gap:4px; max-width:280px;">
                  <?php foreach ($apps as $a): ?>
                    <span class="badge badge-indigo" style="font-size:0.7rem;"><?= esc($a) ?></span>
                  <?php endforeach; ?>
                </div>
              </td>
              <td style="padding:14px 18px; text-align:center;">
                <?php if ($selectedMem): ?>
                  <span class="badge <?= ($decision['sla_compliant'] ?? false) ? 'badge-emerald' : 'badge-amber' ?>" style="font-size:0.75rem;">
                    &rarr; <?= esc($selectedMem['name']) ?> (<?= esc($selectedMem['interface']) ?>)
                  </span>
                  <div style="font-size:0.68rem; color:var(--text-muted); margin-top:3px;"><?= esc($decision['reason'] ?? '') ?></div>
                <?php else: ?>
                  <span class="badge badge-rose" style="font-size:0.75rem;">No viable path</span>
                <?php endif; ?>
              </td>
              <td style="padding:14px 18px; text-align:right; white-space:nowrap;">
                <button type="button" class="btn btn-xs btn-ghost" onclick='editProfile(<?= json_encode($p) ?>)' title="Edit Profile">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                </button>
                <button type="button" class="btn btn-xs btn-ghost text-danger" onclick="deleteProfile('<?= esc($p['id']) ?>', '<?= esc($p['name']) ?>')" title="Delete Profile">
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
<!-- TAB 3: PATH STEERING RULES                                              -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="sdwan-tab-rules" class="tab-pane animate-in" style="display:none;">
  <div class="card card-glass" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--border-color);">
      <div>
        <h3 style="margin:0; font-size:1rem; font-weight:700; color:var(--text-primary);">Policy-Based Path Steering Rules</h3>
        <p style="margin:4px 0 0; font-size:0.78rem; color:var(--text-muted);">Rules are evaluated top-to-bottom. Flows matching subnet criteria and application marks are steered according to their assigned SLA Profile strategy.</p>
      </div>
      <button type="button" class="btn btn-sm btn-primary" onclick="openRuleModal()">+ Add Steering Rule</button>
    </div>
    <div class="table-responsive">
      <table class="table" style="margin:0; width:100%; border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color); background:var(--bg-card-header, rgba(255,255,255,0.02));">
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:center; width:50px;">Pri</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Rule Name</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Source &rarr; Destination Subnet</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Assigned SLA Profile</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Strategy</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:center;">Status</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rules as $r): ?>
            <tr style="border-bottom:1px solid var(--border-color);">
              <td style="padding:14px 18px; text-align:center; font-weight:700; font-family:monospace; color:var(--color-indigo);">
                <?= esc($r['priority']) ?>
              </td>
              <td style="padding:14px 18px; font-weight:600; color:var(--text-primary);">
                <?= esc($r['name']) ?>
              </td>
              <td style="padding:14px 18px; font-family:monospace; font-size:0.8rem; color:var(--text-secondary);">
                <?= esc($r['source_subnet']) ?> &rarr; <?= esc($r['destination_subnet']) ?>
              </td>
              <td style="padding:14px 18px; font-weight:600; color:var(--color-cyan);">
                <?= esc($r['sdwan_profile_id']) ?>
              </td>
              <td style="padding:14px 18px;">
                <span class="badge" style="background:rgba(99,102,241,0.15); color:#818cf8; text-transform:uppercase; font-size:0.7rem;">
                  <?= esc(str_replace('_', ' ', $r['strategy'])) ?>
                </span>
              </td>
              <td style="padding:14px 18px; text-align:center;">
                <?= $r['enabled'] ? '<span class="badge badge-emerald" style="font-size:0.7rem;">Active</span>' : '<span class="badge" style="font-size:0.7rem; background:rgba(100,116,139,0.2); color:var(--text-muted);">Disabled</span>' ?>
              </td>
              <td style="padding:14px 18px; text-align:right; white-space:nowrap;">
                <button type="button" class="btn btn-xs btn-ghost" onclick='editRule(<?= json_encode($r) ?>)' title="Edit Rule">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                </button>
                <button type="button" class="btn btn-xs btn-ghost text-danger" onclick="deleteRule('<?= esc($r['id']) ?>', '<?= esc($r['name']) ?>')" title="Delete Rule">
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
<!-- TAB 4: WAN UPLINK MEMBERS                                               -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="sdwan-tab-members" class="tab-pane animate-in" style="display:none;">
  <div class="card card-glass" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--border-color);">
      <div>
        <h3 style="margin:0; font-size:1rem; font-weight:700; color:var(--text-primary);">Configured WAN Uplink Interfaces</h3>
        <p style="margin:4px 0 0; font-size:0.78rem; color:var(--text-muted);">Physical or virtual WAN gateways participating in the SD-WAN dynamic routing mesh.</p>
      </div>
      <button type="button" class="btn btn-sm btn-primary" onclick="openMemberModal()">+ Add WAN Member</button>
    </div>
    <div class="table-responsive">
      <table class="table" style="margin:0; width:100%; border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color); background:var(--bg-card-header, rgba(255,255,255,0.02));">
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Member Name</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Interface</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Gateway IP</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:left;">Ping Target</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:center;">Cost / Weight</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:center;">Status</th>
            <th style="padding:12px 18px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($members as $m): ?>
            <tr style="border-bottom:1px solid var(--border-color);">
              <td style="padding:14px 18px; font-weight:700; color:var(--text-primary);">
                <?= esc($m['name']) ?>
              </td>
              <td style="padding:14px 18px; font-family:monospace; color:var(--color-cyan);">
                <?= esc($m['interface']) ?>
              </td>
              <td style="padding:14px 18px; font-family:monospace;">
                <?= esc($m['gateway_ip']) ?>
              </td>
              <td style="padding:14px 18px; font-family:monospace; color:var(--text-muted);">
                <?= esc($m['ping_target']) ?>
              </td>
              <td style="padding:14px 18px; text-align:center; font-family:monospace; font-size:0.8rem;">
                Cost: <?= esc($m['cost']) ?> / Wt: <?= esc($m['weight']) ?>
              </td>
              <td style="padding:14px 18px; text-align:center;">
                <span class="badge <?= $m['status'] === 'alive' ? 'badge-emerald' : 'badge-amber' ?>" style="font-size:0.7rem; text-transform:uppercase;">
                  <?= esc($m['status']) ?>
                </span>
              </td>
              <td style="padding:14px 18px; text-align:right; white-space:nowrap;">
                <button type="button" class="btn btn-xs btn-ghost" onclick='editMember(<?= json_encode($m) ?>)' title="Edit Member">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                </button>
                <button type="button" class="btn btn-xs btn-ghost text-danger" onclick="deleteMember('<?= esc($m['id']) ?>', '<?= esc($m['name']) ?>')" title="Delete Member">
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
<!-- TAB 5: ENGINE SETTINGS                                                  -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="sdwan-tab-settings" class="tab-pane animate-in" style="display:none;">
  <div class="card card-glass" style="max-width:680px; padding:24px;">
    <h3 style="margin:0 0 16px; font-size:1.05rem; font-weight:700; color:var(--text-primary);">SD-WAN SLA Engine Parameters</h3>
    <form id="sdwanSettingsForm" onsubmit="saveSdwanSettings(event)">
      <?= csrf_field() ?>
      <div style="margin-bottom:16px;">
        <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
          <input type="checkbox" name="enabled" value="1" <?= ($settings['enabled'] ?? 1) ? 'checked' : '' ?> style="width:16px; height:16px;">
          <div>
            <div style="font-weight:600; font-size:0.85rem; color:var(--text-primary);">Enable Dynamic SD-WAN Engine &amp; Probing Daemon</div>
            <div style="font-size:0.75rem; color:var(--text-muted);">Continuously evaluates WAN quality and synchronizes kernel routing tables.</div>
          </div>
        </label>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Probe Interval (Seconds)</label>
          <input type="number" name="probe_interval_seconds" class="form-control" value="<?= esc($settings['probe_interval_seconds'] ?? 5) ?>" min="1" max="60">
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Packets Per Probe Burst</label>
          <input type="number" name="probe_packet_count" class="form-control" value="<?= esc($settings['probe_packet_count'] ?? 10) ?>" min="3" max="50">
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:18px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Jitter Smoothing Factor (&alpha;)</label>
          <input type="number" step="0.05" name="jitter_smoothing_factor" class="form-control" value="<?= esc($settings['jitter_smoothing_factor'] ?? 0.85) ?>" min="0.1" max="0.99">
          <span style="font-size:0.7rem; color:var(--text-muted);">RFC 3550 statistical variance dampener</span>
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Default Fallback Strategy</label>
          <select name="default_strategy" class="form-select">
            <option value="best_quality" <?= ($settings['default_strategy'] ?? '') === 'best_quality' ? 'selected' : '' ?>>Best Quality (Lowest Loss + Latency)</option>
            <option value="lowest_cost" <?= ($settings['default_strategy'] ?? '') === 'lowest_cost' ? 'selected' : '' ?>>Lowest Cost Uplink</option>
            <option value="round_robin" <?= ($settings['default_strategy'] ?? '') === 'round_robin' ? 'selected' : '' ?>>Weighted Round Robin</option>
          </select>
        </div>
      </div>

      <button type="submit" class="btn btn-primary">Save Engine Settings</button>
    </form>
  </div>
</div>

<!-- ── MODAL: Member Modal ── -->
<div id="memberModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="card card-glass" style="width:95%; max-width:520px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-primary);" id="memberModalTitle">Add WAN Member</h3>
      <button type="button" class="btn btn-xs btn-ghost" onclick="closeMemberModal()">&times;</button>
    </div>
    <form id="memberForm" onsubmit="saveMember(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="memId" value="">
      <div style="margin-bottom:12px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Member Name</label>
        <input type="text" name="name" id="memName" class="form-control" required placeholder="e.g. Starlink Low-Earth Orbit Satellite">
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Interface</label>
          <input type="text" name="interface" id="memIface" class="form-control" required placeholder="wan3 or eth2">
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Gateway IP</label>
          <input type="text" name="gateway_ip" id="memGw" class="form-control" required placeholder="192.168.100.1">
        </div>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Cost</label>
          <input type="number" name="cost" id="memCost" class="form-control" value="10">
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Weight</label>
          <input type="number" name="weight" id="memWeight" class="form-control" value="100">
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Priority</label>
          <input type="number" name="priority" id="memPriority" class="form-control" value="1">
        </div>
      </div>
      <div style="margin-bottom:14px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">SLA Ping Target</label>
        <input type="text" name="ping_target" id="memPingTarget" class="form-control" value="8.8.8.8">
      </div>
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeMemberModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Member</button>
      </div>
    </form>
  </div>
</div>

<!-- ── MODAL: Profile Modal ── -->
<div id="profileModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="card card-glass" style="width:95%; max-width:580px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-primary);" id="profileModalTitle">Add Performance SLA Profile</h3>
      <button type="button" class="btn btn-xs btn-ghost" onclick="closeProfileModal()">&times;</button>
    </div>
    <form id="profileForm" onsubmit="saveProfile(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="profId" value="">
      <div style="margin-bottom:12px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Profile Name</label>
        <input type="text" name="name" id="profName" class="form-control" required placeholder="e.g. Critical Video Conferencing">
      </div>
      <div style="margin-bottom:12px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Description</label>
        <input type="text" name="description" id="profDesc" class="form-control" placeholder="Purpose and SLA bounds">
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Max Loss (%)</label>
          <input type="number" step="0.1" name="sla_max_loss_pct" id="profMaxLoss" class="form-control" value="1.0">
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Max Latency (ms)</label>
          <input type="number" step="1" name="sla_max_latency_ms" id="profMaxLatency" class="form-control" value="100">
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Max Jitter (ms)</label>
          <input type="number" step="1" name="sla_max_jitter_ms" id="profMaxJitter" class="form-control" value="15">
        </div>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Preferred Member</label>
          <select name="preferred_member_id" id="profPrefMem" class="form-select">
            <option value="">-- None --</option>
            <?php foreach ($members as $m): ?>
              <option value="<?= esc($m['id']) ?>"><?= esc($m['name']) ?> (<?= esc($m['interface']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Backup Member</label>
          <select name="backup_member_id" id="profBackupMem" class="form-select">
            <option value="">-- None --</option>
            <?php foreach ($members as $m): ?>
              <option value="<?= esc($m['id']) ?>"><?= esc($m['name']) ?> (<?= esc($m['interface']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="margin-bottom:14px;">
        <label class="form-label" style="font-weight:600; font-size:0.8rem;">Assigned nDPI Applications (Comma-separated)</label>
        <input type="text" name="assigned_applications" id="profApps" class="form-control" placeholder="Zoom, Teams, SIP, WebRTC">
      </div>
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeProfileModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Profile</button>
      </div>
    </form>
  </div>
</div>

<!-- ── MODAL: Rule Modal ── -->
<div id="ruleModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="card card-glass" style="width:95%; max-width:520px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-primary);" id="ruleModalTitle">Add Steering Rule</h3>
      <button type="button" class="btn btn-xs btn-ghost" onclick="closeRuleModal()">&times;</button>
    </div>
    <form id="ruleForm" onsubmit="saveRule(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="ruleId" value="">
      <div style="display:grid; grid-template-columns:80px 1fr; gap:10px; margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Priority</label>
          <input type="number" name="priority" id="rulePri" class="form-control" value="10" required>
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Rule Name</label>
          <input type="text" name="name" id="ruleName" class="form-control" required placeholder="e.g. Branch Office Collaboration Steering">
        </div>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Source Subnet</label>
          <input type="text" name="source_subnet" id="ruleSrc" class="form-control" value="0.0.0.0/0">
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Destination Subnet</label>
          <input type="text" name="destination_subnet" id="ruleDst" class="form-control" value="0.0.0.0/0">
        </div>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">SLA Profile</label>
          <select name="sdwan_profile_id" id="ruleProf" class="form-select" required>
            <?php foreach ($profiles as $p): ?>
              <option value="<?= esc($p['id']) ?>"><?= esc($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label" style="font-weight:600; font-size:0.8rem;">Strategy</label>
          <select name="strategy" id="ruleStrat" class="form-select">
            <option value="best_quality">Best Quality Link</option>
            <option value="lowest_cost">Lowest Cost</option>
            <option value="failover">Manual Priority Failover</option>
            <option value="round_robin">Weighted Round Robin</option>
          </select>
        </div>
      </div>
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeRuleModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Rule</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchSdwanTab(tab, el) {
  document.querySelectorAll('.tab-pane').forEach(p => p.style.display = 'none');
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  const target = document.getElementById('sdwan-tab-' + tab);
  if (target) target.style.display = 'block';
  if (el) el.classList.add('active');
}

// ── Member Handlers ──
function openMemberModal() {
  document.getElementById('memberForm').reset();
  document.getElementById('memId').value = '';
  document.getElementById('memberModalTitle').innerText = 'Add WAN Member';
  document.getElementById('memberModal').style.display = 'flex';
}
function closeMemberModal() {
  document.getElementById('memberModal').style.display = 'none';
}
function editMember(m) {
  document.getElementById('memberForm').reset();
  document.getElementById('memId').value = m.id;
  document.getElementById('memName').value = m.name;
  document.getElementById('memIface').value = m.interface;
  document.getElementById('memGw').value = m.gateway_ip;
  document.getElementById('memCost').value = m.cost;
  document.getElementById('memWeight').value = m.weight;
  document.getElementById('memPriority').value = m.priority;
  document.getElementById('memPingTarget').value = m.ping_target;
  document.getElementById('memberModalTitle').innerText = 'Edit WAN Member';
  document.getElementById('memberModal').style.display = 'flex';
}
function saveMember(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('memberForm'));
  MicronScheduler.fetch('<?= base_url('routing/sdwan/member/save') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      window.location.reload();
    } else {
      alert(data.message || 'Error saving member.');
    }
  });
}
function deleteMember(id, name) {
  if (!confirm(`Delete WAN member '${name}'?`)) return;
  const fd = new FormData();
  fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');
  MicronScheduler.fetch('<?= base_url('routing/sdwan/member') ?>/' + id + '/delete', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) window.location.reload();
  });
}

// ── Profile Handlers ──
function openProfileModal() {
  document.getElementById('profileForm').reset();
  document.getElementById('profId').value = '';
  document.getElementById('profileModalTitle').innerText = 'Add Performance SLA Profile';
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
  document.getElementById('profMaxLoss').value = p.sla_max_loss_pct;
  document.getElementById('profMaxLatency').value = p.sla_max_latency_ms;
  document.getElementById('profMaxJitter').value = p.sla_max_jitter_ms;
  document.getElementById('profPrefMem').value = p.preferred_member_id || '';
  document.getElementById('profBackupMem').value = p.backup_member_id || '';

  let apps = [];
  try { apps = JSON.parse(p.assigned_applications || '[]'); } catch(e){}
  document.getElementById('profApps').value = Array.isArray(apps) ? apps.join(', ') : (p.assigned_applications || '');

  document.getElementById('profileModalTitle').innerText = 'Edit SLA Profile';
  document.getElementById('profileModal').style.display = 'flex';
}
function saveProfile(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('profileForm'));
  MicronScheduler.fetch('<?= base_url('routing/sdwan/profile/save') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      window.location.reload();
    } else {
      alert(data.message || 'Error saving profile.');
    }
  });
}
function deleteProfile(id, name) {
  if (!confirm(`Delete SLA Profile '${name}'?`)) return;
  const fd = new FormData();
  fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');
  MicronScheduler.fetch('<?= base_url('routing/sdwan/profile') ?>/' + id + '/delete', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) window.location.reload();
  });
}

// ── Rule Handlers ──
function openRuleModal() {
  document.getElementById('ruleForm').reset();
  document.getElementById('ruleId').value = '';
  document.getElementById('ruleModalTitle').innerText = 'Add Steering Rule';
  document.getElementById('ruleModal').style.display = 'flex';
}
function closeRuleModal() {
  document.getElementById('ruleModal').style.display = 'none';
}
function editRule(r) {
  document.getElementById('ruleForm').reset();
  document.getElementById('ruleId').value = r.id;
  document.getElementById('rulePri').value = r.priority;
  document.getElementById('ruleName').value = r.name;
  document.getElementById('ruleSrc').value = r.source_subnet;
  document.getElementById('ruleDst').value = r.destination_subnet;
  document.getElementById('ruleProf').value = r.sdwan_profile_id;
  document.getElementById('ruleStrat').value = r.strategy;
  document.getElementById('ruleModalTitle').innerText = 'Edit Steering Rule';
  document.getElementById('ruleModal').style.display = 'flex';
}
function saveRule(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('ruleForm'));
  MicronScheduler.fetch('<?= base_url('routing/sdwan/rule/save') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      window.location.reload();
    } else {
      alert(data.message || 'Error saving rule.');
    }
  });
}
function deleteRule(id, name) {
  if (!confirm(`Delete Steering Rule '${name}'?`)) return;
  const fd = new FormData();
  fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');
  MicronScheduler.fetch('<?= base_url('routing/sdwan/rule') ?>/' + id + '/delete', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) window.location.reload();
  });
}

// ── Settings Handler ──
function saveSdwanSettings(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('sdwanSettingsForm'));
  MicronScheduler.fetch('<?= base_url('routing/sdwan/settings/save') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    alert(data.message || 'Settings saved.');
  });
}

// ── Probing Trigger ──
function runProbeNow() {
  const btn = document.getElementById('probeBtn');
  btn.disabled = true;
  btn.innerText = 'Probing...';
  MicronScheduler.fetch('<?= base_url('routing/sdwan/probe-now') ?>')
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerText = 'Probe All Uplinks Now';
      window.location.reload();
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerText = 'Probe All Uplinks Now';
    });
}

function applySdwanPolicies() {
  if (!confirm('Apply SD-WAN SLA path steering policies to netfilter and kernel routing tables?')) return;
  const fd = new FormData();
  fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');
  MicronScheduler.fetch('<?= base_url('routing/sdwan/apply') ?>', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    alert(data.message || 'SD-WAN routing rules active.');
  });
}
</script>

<?= $this->endSection() ?>
