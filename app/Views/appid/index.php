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
      <span class="current">Application Identification (AppID)</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-indigo">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
        </svg>
      </div>
      Application Identification &amp; Layer 7 DPI
    </h1>
    <p class="page-subtitle">Granular Layer 7 application detection via nDPI with flow-level policy enforcement, bandwidth shaping, and cryptographic protocol classification.</p>
  </div>
  <div class="page-header-actions">
    <button type="button" class="btn btn-ghost" onclick="openPreviewModal()" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
      </svg>
      Preview Ruleset
    </button>

    <button type="button" class="btn btn-primary" onclick="openPolicyModal()" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
      </svg>
      Create Policy
    </button>

    <form method="POST" action="<?= base_url('appid/apply') ?>" id="applyDpiForm" style="margin:0;">
      <?= csrf_field() ?>
      <button type="button" onclick="applyDpiRules()" class="btn btn-success" style="display:inline-flex; align-items:center; gap:6px;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Apply Changes
      </button>
    </form>
  </div>
</div>

<!-- ── KPI Summary Cards ── -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:24px;" class="animate-in animate-in-delay-1">
  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-emerald);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">DPI Engine State</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="width:10px; height:10px; border-radius:50%; background:var(--color-emerald); display:inline-block; box-shadow:0 0 8px var(--color-emerald);"></span>
      <span style="font-size:1.15rem; font-weight:700; color:var(--text-primary); text-transform:uppercase;">
        <?= strtoupper(esc($dpiStatus['mode'] ?? 'inline_block')) ?>
      </span>
    </div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px; font-family:var(--font-mono);">
      <?= esc($dpiStatus['engine'] ?? 'nDPI 4.10') ?> &bull; Uptime <?= esc($dpiStatus['uptime'] ?? 'active') ?>
    </div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-cyan, #06b6d4);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Active L7 Flows</div>
    <div style="font-size:1.6rem; font-weight:700; color:var(--color-cyan, #06b6d4); margin-top:2px; font-family:var(--font-mono);" id="kpi-active-flows">
      <?= number_format((int)($dpiStatus['active_flows'] ?? 0)) ?>
    </div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">
      Throughput: <strong style="color:var(--text-primary);"><?= esc($dpiStatus['throughput_mbps'] ?? '0') ?> Mbps</strong> &bull; <?= esc($dpiStatus['memory_usage'] ?? '46 MB') ?>
    </div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-red);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Threats &amp; Violations Blocked</div>
    <div style="font-size:1.6rem; font-weight:700; color:var(--color-red); margin-top:2px; font-family:var(--font-mono);">
      <?= number_format((int)($dpiStatus['blocked_flows'] ?? 0)) ?>
    </div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">
      Cryptomining, unauthorized P2P &amp; high-risk flows
    </div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-purple, #8b5cf6);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Protocol Catalogue</div>
    <div style="font-size:1.6rem; font-weight:700; color:var(--color-purple, #8b5cf6); margin-top:2px; font-family:var(--font-mono);">
      <?= count($apps) ?>
    </div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">
      Across <?= count($categories) ?> Categories &bull; <?= count($signatures) ?> Custom Signatures
    </div>
  </div>
</div>

<!-- ── Navigation Tabs ── -->
<div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:20px; padding:6px 12px;">
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;" role="tablist">
    <button type="button" class="btn btn-primary btn-sm dpi-tab-btn active" id="tabBtn-policies" onclick="switchDpiTab('policies')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
      Application Policies
      <span class="badge badge-indigo" style="font-size:0.65rem; padding:1px 6px;"><?= count($policies) ?></span>
    </button>

    <button type="button" class="btn btn-ghost btn-sm dpi-tab-btn" id="tabBtn-live" onclick="switchDpiTab('live')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
      </svg>
      Live Flow Telemetry
      <span class="badge badge-cyan" style="font-size:0.65rem; padding:1px 6px;" id="badge-live-count">Active</span>
    </button>

    <button type="button" class="btn btn-ghost btn-sm dpi-tab-btn" id="tabBtn-apps" onclick="switchDpiTab('apps')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
      </svg>
      Application Catalogue
      <span class="badge badge-purple" style="font-size:0.65rem; padding:1px 6px;"><?= count($apps) ?></span>
    </button>

    <button type="button" class="btn btn-ghost btn-sm dpi-tab-btn" id="tabBtn-signatures" onclick="switchDpiTab('signatures')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
      </svg>
      Custom Signatures
      <span class="badge badge-amber" style="font-size:0.65rem; padding:1px 6px;"><?= count($signatures) ?></span>
    </button>

    <button type="button" class="btn btn-ghost btn-sm dpi-tab-btn" id="tabBtn-settings" onclick="switchDpiTab('settings')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
      </svg>
      Engine Settings
    </button>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB 1: APPLICATION POLICIES                                -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div id="tabContent-policies" class="dpi-tab-content">
  <div class="card card-glass animate-in">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-subtle); display:flex; justify-content:space-between; align-items:center;">
      <div>
        <h2 style="font-size:1.05rem; font-weight:700; margin:0; color:var(--text-primary);">Layer 7 Application Control Rulebase</h2>
        <p style="font-size:0.78rem; color:var(--text-secondary); margin:2px 0 0 0;">Evaluated sequentially from top to bottom. Packets matching marked AppIDs are acted upon before egress.</p>
      </div>
      <button type="button" class="btn btn-primary btn-sm" onclick="openPolicyModal()">
        + Add Policy Rule
      </button>
    </div>

    <div class="table-responsive">
      <table class="table" style="width:100%; border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-subtle); text-align:left; font-size:0.75rem; color:var(--text-muted); text-transform:uppercase;">
            <th style="padding:12px 16px; width:60px;">Seq</th>
            <th style="padding:12px 16px;">Policy Name</th>
            <th style="padding:12px 16px;">Interfaces</th>
            <th style="padding:12px 16px;">Target Applications / Categories</th>
            <th style="padding:12px 16px;">Action</th>
            <th style="padding:12px 16px;">Bandwidth</th>
            <th style="padding:12px 16px;">Status</th>
            <th style="padding:12px 16px; text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($policies)): ?>
            <tr>
              <td colspan="8" style="padding:40px; text-align:center; color:var(--text-muted);">
                No application control policies configured. Click "+ Add Policy Rule" to create one.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($policies as $pol): ?>
              <tr style="border-bottom:1px solid var(--border-subtle); font-size:0.83rem;">
                <td style="padding:12px 16px; font-family:var(--font-mono); font-weight:700; color:var(--text-muted);">
                  #<?= esc($pol['sequence']) ?>
                </td>
                <td style="padding:12px 16px; font-weight:600; color:var(--text-primary);">
                  <?= esc($pol['name']) ?>
                  <?php if (!empty($pol['log_enabled'])): ?>
                    <span class="badge badge-ghost" style="font-size:0.65rem; margin-left:4px;">LOG</span>
                  <?php endif; ?>
                </td>
                <td style="padding:12px 16px; font-family:var(--font-mono); color:var(--text-secondary);">
                  <span style="color:var(--color-cyan);"><?= esc($pol['src_interface']) ?></span>
                  &rarr;
                  <span style="color:var(--color-indigo, #6366f1);"><?= esc($pol['dst_interface']) ?></span>
                </td>
                <td style="padding:12px 16px;">
                  <div style="display:flex; flex-wrap:wrap; gap:4px; max-width:340px;">
                    <?php if (!empty($pol['cat_names'])): ?>
                      <?php foreach ($pol['cat_names'] as $cName): ?>
                        <span class="badge badge-purple" style="font-size:0.7rem;"><?= esc($cName) ?> (Cat)</span>
                      <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (!empty($pol['app_names'])): ?>
                      <?php foreach ($pol['app_names'] as $aName): ?>
                        <span class="badge badge-indigo" style="font-size:0.7rem;"><?= esc($aName) ?></span>
                      <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (empty($pol['cat_names']) && empty($pol['app_names'])): ?>
                      <span style="color:var(--text-muted); font-size:0.75rem;">None specified</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td style="padding:12px 16px;">
                  <?php if ($pol['action'] === 'block'): ?>
                    <span class="badge badge-red" style="font-weight:700;">BLOCK</span>
                  <?php elseif ($pol['action'] === 'shape'): ?>
                    <span class="badge badge-amber" style="font-weight:700;">SHAPE / RATE LIMIT</span>
                  <?php else: ?>
                    <span class="badge badge-emerald" style="font-weight:700;">ALLOW</span>
                  <?php endif; ?>
                </td>
                <td style="padding:12px 16px; font-family:var(--font-mono); color:var(--text-secondary);">
                  <?= !empty($pol['bandwidth_limit_kbps']) ? number_format($pol['bandwidth_limit_kbps']) . ' Kbps' : 'Unlimited' ?>
                </td>
                <td style="padding:12px 16px;">
                  <button type="button" class="btn btn-ghost btn-sm" onclick="togglePolicy('<?= esc($pol['id']) ?>')" style="padding:2px 8px; font-size:0.75rem;">
                    <?php if (!empty($pol['enabled'])): ?>
                      <span style="color:var(--color-emerald); font-weight:700;">&bull; Enabled</span>
                    <?php else: ?>
                      <span style="color:var(--text-muted);">&bull; Disabled</span>
                    <?php endif; ?>
                  </button>
                </td>
                <td style="padding:12px 16px; text-align:right;">
                  <div style="display:inline-flex; gap:6px;">
                    <button type="button" class="btn btn-ghost btn-sm" onclick='editPolicy(<?= json_encode($pol) ?>)' title="Edit Policy">
                      Edit
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="deletePolicy('<?= esc($pol['id']) ?>', '<?= esc($pol['name']) ?>')" title="Delete Policy">
                      Delete
                    </button>
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

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB 2: LIVE FLOW TELEMETRY                                  -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div id="tabContent-live" class="dpi-tab-content" style="display:none;">
  <div class="card card-glass animate-in">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-subtle); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <div>
        <h2 style="font-size:1.05rem; font-weight:700; margin:0; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
          <span style="width:8px; height:8px; border-radius:50%; background:var(--color-cyan); display:inline-block; box-shadow:0 0 6px var(--color-cyan);"></span>
          Real-Time Layer 7 Flow Stream
        </h2>
        <p style="font-size:0.78rem; color:var(--text-secondary); margin:2px 0 0 0;">Packets inspected at ingress and classified into application signatures by nDPI.</p>
      </div>
      <div style="display:flex; align-items:center; gap:10px;">
        <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.78rem; color:var(--text-secondary); cursor:pointer;">
          <input type="checkbox" id="autoRefreshFlows" checked onchange="toggleAutoRefresh(this.checked)"> Auto-Refresh (3s)
        </label>
        <button type="button" class="btn btn-ghost btn-sm" onclick="fetchLiveFlows()" style="display:inline-flex; align-items:center; gap:4px;">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Refresh Now
        </button>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table" style="width:100%; border-collapse:collapse;" id="liveFlowsTable">
        <thead>
          <tr style="border-bottom:1px solid var(--border-subtle); text-align:left; font-size:0.75rem; color:var(--text-muted); text-transform:uppercase;">
            <th style="padding:12px 16px;">Source &amp; Port</th>
            <th style="padding:12px 16px;">Destination &amp; Port</th>
            <th style="padding:12px 16px;">Proto</th>
            <th style="padding:12px 16px;">Identified Application</th>
            <th style="padding:12px 16px;">Category</th>
            <th style="padding:12px 16px;">Risk</th>
            <th style="padding:12px 16px;">Rate / Volume</th>
            <th style="padding:12px 16px;">Policy Action</th>
          </tr>
        </thead>
        <tbody id="liveFlowsBody">
          <tr>
            <td colspan="8" style="padding:40px; text-align:center; color:var(--text-muted);">
              Streaming live flows from nDPI engine...
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB 3: APPLICATION CATALOGUE                               -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div id="tabContent-apps" class="dpi-tab-content" style="display:none;">
  <div class="card card-glass animate-in" style="margin-bottom:20px;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-subtle); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <div>
        <h2 style="font-size:1.05rem; font-weight:700; margin:0; color:var(--text-primary);">Predefined Application Signatures</h2>
        <p style="font-size:0.78rem; color:var(--text-secondary); margin:2px 0 0 0;">System library of recognized applications, port hints, and netfilter connection mark mappings.</p>
      </div>
      <div style="display:flex; gap:10px; align-items:center;">
        <input type="text" id="appFilterInput" placeholder="Filter applications..." onkeyup="filterAppCatalogue()" class="form-control" style="width:240px; font-size:0.8rem; padding:6px 12px; background:var(--bg-glass); border:1px solid var(--border-subtle); border-radius:var(--radius-sm); color:var(--text-primary);">
      </div>
    </div>

    <!-- Category Pills -->
    <div style="padding:12px 20px; border-bottom:1px solid var(--border-subtle); display:flex; gap:8px; flex-wrap:wrap;">
      <button type="button" class="btn btn-ghost btn-sm app-cat-pill active" onclick="filterByCat('all', this)" style="font-size:0.75rem;">
        All (<?= count($apps) ?>)
      </button>
      <?php foreach ($categories as $cat): ?>
        <button type="button" class="btn btn-ghost btn-sm app-cat-pill" onclick="filterByCat('<?= esc($cat['id']) ?>', this)" style="font-size:0.75rem;">
          <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:<?= esc($cat['color']) ?>; margin-right:4px;"></span>
          <?= esc($cat['name']) ?> (<?= esc($cat['app_count']) ?>)
        </button>
      <?php endforeach; ?>
    </div>

    <div class="table-responsive">
      <table class="table" style="width:100%; border-collapse:collapse;" id="appCatalogueTable">
        <thead>
          <tr style="border-bottom:1px solid var(--border-subtle); text-align:left; font-size:0.75rem; color:var(--text-muted); text-transform:uppercase;">
            <th style="padding:12px 16px;">Application Name</th>
            <th style="padding:12px 16px;">Category</th>
            <th style="padding:12px 16px;">nDPI Proto ID</th>
            <th style="padding:12px 16px;">Netfilter Mark</th>
            <th style="padding:12px 16px;">Risk Level</th>
            <th style="padding:12px 16px;">Ports / Hints</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($apps as $app): ?>
            <tr class="app-row" data-cat="<?= esc($app['category_id']) ?>" data-name="<?= strtolower(esc($app['name'])) ?>" style="border-bottom:1px solid var(--border-subtle); font-size:0.83rem;">
              <td style="padding:12px 16px; font-weight:600; color:var(--text-primary);">
                <?= esc($app['name']) ?>
                <div style="font-size:0.72rem; color:var(--text-muted); font-family:var(--font-mono);"><?= esc($app['id']) ?></div>
              </td>
              <td style="padding:12px 16px;">
                <span class="badge" style="background:<?= esc($app['category_color'] ?? '#6366f1') ?>22; color:<?= esc($app['category_color'] ?? '#6366f1') ?>; border:1px solid <?= esc($app['category_color'] ?? '#6366f1') ?>44; font-size:0.75rem;">
                  <?= esc($app['category_name'] ?? $app['category_id']) ?>
                </span>
              </td>
              <td style="padding:12px 16px; font-family:var(--font-mono); color:var(--text-secondary);">
                #<?= esc($app['proto_id']) ?>
              </td>
              <td style="padding:12px 16px; font-family:var(--font-mono); color:var(--color-cyan);">
                <?= esc($app['mark_hex']) ?>
              </td>
              <td style="padding:12px 16px;">
                <?php
                  $risk = (int)$app['risk_level'];
                  $riskBadge = match ($risk) {
                      5 => ['badge-red', 'Critical (Risk 5)'],
                      4 => ['badge-amber', 'High (Risk 4)'],
                      3 => ['badge-amber', 'Medium (Risk 3)'],
                      2 => ['badge-cyan', 'Moderate (Risk 2)'],
                      default => ['badge-emerald', 'Low (Risk 1)'],
                  };
                ?>
                <span class="badge <?= $riskBadge[0] ?>" style="font-size:0.7rem; font-weight:600;">
                  <?= $riskBadge[1] ?>
                </span>
              </td>
              <td style="padding:12px 16px; font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);">
                <?= esc($app['ports_hint'] ?: 'Dynamic L7 classification') ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB 4: CUSTOM SIGNATURES                                    -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div id="tabContent-signatures" class="dpi-tab-content" style="display:none;">
  <div class="card card-glass animate-in">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-subtle); display:flex; justify-content:space-between; align-items:center;">
      <div>
        <h2 style="font-size:1.05rem; font-weight:700; margin:0; color:var(--text-primary);">Custom Layer 7 Regex &amp; Host Signatures</h2>
        <p style="font-size:0.78rem; color:var(--text-secondary); margin:2px 0 0 0;">Define proprietary application patterns, internal protocol signatures, and custom SNI expressions.</p>
      </div>
      <button type="button" class="btn btn-primary btn-sm" onclick="openSignatureModal()">
        + Add Custom Signature
      </button>
    </div>

    <div class="table-responsive">
      <table class="table" style="width:100%; border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-subtle); text-align:left; font-size:0.75rem; color:var(--text-muted); text-transform:uppercase;">
            <th style="padding:12px 16px;">Signature Name</th>
            <th style="padding:12px 16px;">Pattern Type</th>
            <th style="padding:12px 16px;">Match Pattern</th>
            <th style="padding:12px 16px;">Protocol &amp; Port</th>
            <th style="padding:12px 16px;">Status</th>
            <th style="padding:12px 16px; text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($signatures)): ?>
            <tr>
              <td colspan="6" style="padding:40px; text-align:center; color:var(--text-muted);">
                No custom signatures defined. Built-in nDPI protocol database covers standard Internet traffic.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($signatures as $sig): ?>
              <tr style="border-bottom:1px solid var(--border-subtle); font-size:0.83rem;">
                <td style="padding:12px 16px; font-weight:600; color:var(--text-primary);">
                  <?= esc($sig['name']) ?>
                </td>
                <td style="padding:12px 16px;">
                  <span class="badge badge-purple" style="font-size:0.7rem; text-transform:uppercase;"><?= esc($sig['pattern_type']) ?></span>
                </td>
                <td style="padding:12px 16px; font-family:var(--font-mono); color:var(--text-secondary); max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                  <?= esc($sig['pattern']) ?>
                </td>
                <td style="padding:12px 16px; font-family:var(--font-mono); color:var(--color-cyan);">
                  <?= strtoupper(esc($sig['protocol'])) ?> / <?= esc($sig['port']) ?>
                </td>
                <td style="padding:12px 16px;">
                  <?= !empty($sig['enabled']) ? '<span style="color:var(--color-emerald); font-weight:700;">&bull; Active</span>' : '<span style="color:var(--text-muted);">&bull; Inactive</span>' ?>
                </td>
                <td style="padding:12px 16px; text-align:right;">
                  <button type="button" class="btn btn-danger btn-sm" onclick="deleteSignature('<?= esc($sig['id']) ?>', '<?= esc($sig['name']) ?>')">
                    Delete
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

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB 5: DPI ENGINE SETTINGS                                  -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div id="tabContent-settings" class="dpi-tab-content" style="display:none;">
  <div class="card card-glass animate-in" style="max-width:800px;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-subtle);">
      <h2 style="font-size:1.05rem; font-weight:700; margin:0; color:var(--text-primary);">nDPI Daemon &amp; Kernel Hook Settings</h2>
      <p style="font-size:0.78rem; color:var(--text-secondary); margin:2px 0 0 0;">Tune the inspection depth, flow table size, and inline blocking enforcement behavior.</p>
    </div>

    <form method="POST" action="<?= base_url('appid/settings/save') ?>" id="dpiSettingsForm" style="padding:20px;">
      <?= csrf_field() ?>

      <div style="margin-bottom:18px;">
        <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; font-weight:600; color:var(--text-primary); cursor:pointer;">
          <input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?> style="width:16px; height:16px;">
          Enable Layer 7 Deep Packet Inspection Engine
        </label>
        <div style="font-size:0.75rem; color:var(--text-secondary); margin-left:24px; margin-top:2px;">
          When active, packet flows are inspected by nDPI and tagged with netfilter ct marks for policy matching.
        </div>
      </div>

      <div style="margin-bottom:18px;">
        <label style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-primary); margin-bottom:6px;">Engine Operating Mode</label>
        <select name="engine_mode" class="form-control" style="width:100%; max-width:400px; padding:8px 12px; background:var(--bg-glass); border:1px solid var(--border-subtle); border-radius:var(--radius-sm); color:var(--text-primary);">
          <option value="inline_block" <?= ($settings['engine_mode'] ?? '') === 'inline_block' ? 'selected' : '' ?>>Inline Blocking (Enforce drop/reject actions on wire)</option>
          <option value="monitor_only" <?= ($settings['engine_mode'] ?? '') === 'monitor_only' ? 'selected' : '' ?>>Monitor Only (Telemetry logging only, ignore drops)</option>
        </select>
      </div>

      <div style="margin-bottom:18px;">
        <label style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-primary); margin-bottom:6px;">Inspection Depth (Packets per flow)</label>
        <input type="number" name="inspection_depth_packets" value="<?= esc($settings['inspection_depth_packets'] ?? 10) ?>" min="1" max="50" class="form-control" style="width:100%; max-width:200px; padding:8px 12px; background:var(--bg-glass); border:1px solid var(--border-subtle); border-radius:var(--radius-sm); color:var(--text-primary);">
        <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:4px;">
          Number of initial flow packets analyzed to identify the application. 10 packets is optimal for 99.8% of modern TLS/SNI and QUIC handshakes.
        </div>
      </div>

      <div style="margin-bottom:18px;">
        <label style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-primary); margin-bottom:6px;">Maximum Conntrack Flow Table Size</label>
        <input type="number" name="flow_table_max" value="<?= esc($settings['flow_table_max'] ?? 262144) ?>" min="1024" max="1048576" step="1024" class="form-control" style="width:100%; max-width:200px; padding:8px 12px; background:var(--bg-glass); border:1px solid var(--border-subtle); border-radius:var(--radius-sm); color:var(--text-primary);">
        <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:4px;">
          Size of in-kernel hash table for simultaneous stateful flow classification.
        </div>
      </div>

      <div style="margin-top:24px; padding-top:16px; border-top:1px solid var(--border-subtle);">
        <button type="submit" class="btn btn-primary">Save Engine Settings</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MODAL: ADD / EDIT POLICY                                   -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div id="policyModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="card card-glass animate-in" style="width:90%; max-width:650px; max-height:90vh; overflow-y:auto; padding:0; border:1px solid var(--border-subtle); box-shadow:0 12px 40px rgba(0,0,0,0.6);">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-subtle); display:flex; justify-content:space-between; align-items:center;">
      <h3 style="font-size:1.1rem; font-weight:700; margin:0; color:var(--text-primary);" id="policyModalTitle">Create Application Policy</h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closePolicyModal()">&times;</button>
    </div>

    <form method="POST" action="<?= base_url('appid/policy/save') ?>" id="policyForm" style="padding:20px;">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="pol_id" value="">

      <div style="margin-bottom:14px;">
        <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--text-primary); margin-bottom:4px;">Policy Name</label>
        <input type="text" name="name" id="pol_name" required placeholder="e.g. Block Cryptomining or Shape Streaming" class="form-control" style="width:100%; padding:8px 12px; background:var(--bg-glass); border:1px solid var(--border-subtle); border-radius:var(--radius-sm); color:var(--text-primary);">
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
        <div>
          <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--text-primary); margin-bottom:4px;">Source Interface</label>
          <select name="src_interface" id="pol_src_interface" class="form-control" style="width:100%; padding:8px 12px; background:var(--bg-glass); border:1px solid var(--border-subtle); border-radius:var(--radius-sm); color:var(--text-primary);">
            <option value="lan">lan (LAN Bridge)</option>
            <option value="any">any (All Interfaces)</option>
            <?php foreach ($interfaces as $iface): ?>
              <option value="<?= esc($iface['name']) ?>"><?= esc($iface['name']) ?> (<?= esc($iface['physical_device'] ?? $iface['name']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--text-primary); margin-bottom:4px;">Destination Interface</label>
          <select name="dst_interface" id="pol_dst_interface" class="form-control" style="width:100%; padding:8px 12px; background:var(--bg-glass); border:1px solid var(--border-subtle); border-radius:var(--radius-sm); color:var(--text-primary);">
            <option value="wan">wan (WAN Gateway)</option>
            <option value="any">any (All Interfaces)</option>
            <?php foreach ($interfaces as $iface): ?>
              <option value="<?= esc($iface['name']) ?>"><?= esc($iface['name']) ?> (<?= esc($iface['physical_device'] ?? $iface['name']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="margin-bottom:14px;">
        <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--text-primary); margin-bottom:4px;">Action</label>
        <div style="display:flex; gap:16px;">
          <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.85rem; cursor:pointer;">
            <input type="radio" name="action" value="block" checked onchange="toggleBandwidthField(this.value)"> <strong style="color:var(--color-red);">Block / Drop</strong>
          </label>
          <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.85rem; cursor:pointer;">
            <input type="radio" name="action" value="shape" onchange="toggleBandwidthField(this.value)"> <strong style="color:var(--color-amber);">Shape / Rate Limit</strong>
          </label>
          <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.85rem; cursor:pointer;">
            <input type="radio" name="action" value="allow" onchange="toggleBandwidthField(this.value)"> <strong style="color:var(--color-emerald);">Allow &amp; Log</strong>
          </label>
        </div>
      </div>

      <div id="bandwidthField" style="display:none; margin-bottom:14px; background:rgba(245,158,11,0.06); border:1px solid rgba(245,158,11,0.2); padding:10px 14px; border-radius:var(--radius-sm);">
        <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--color-amber); margin-bottom:4px;">Bandwidth Rate Limit (Kbps)</label>
        <input type="number" name="bandwidth_limit_kbps" id="pol_bandwidth_limit_kbps" placeholder="e.g. 1024 for 1 Mbps" class="form-control" style="width:100%; max-width:240px; padding:6px 10px; background:var(--bg-glass); border:1px solid var(--border-subtle); border-radius:var(--radius-sm); color:var(--text-primary);">
      </div>

      <!-- Categories Selector -->
      <div style="margin-bottom:14px;">
        <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--text-primary); margin-bottom:6px;">Target Entire Categories</label>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:6px; max-height:120px; overflow-y:auto; padding:8px; border:1px solid var(--border-subtle); border-radius:var(--radius-sm); background:var(--bg-glass);">
          <?php foreach ($categories as $cat): ?>
            <label style="display:flex; align-items:center; gap:6px; font-size:0.78rem; color:var(--text-secondary); cursor:pointer;">
              <input type="checkbox" name="category_ids[]" value="<?= esc($cat['id']) ?>" class="pol-cat-chk">
              <span style="width:6px; height:6px; border-radius:50%; background:<?= esc($cat['color']) ?>;"></span>
              <?= esc($cat['name']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Applications Selector -->
      <div style="margin-bottom:14px;">
        <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--text-primary); margin-bottom:6px;">Target Specific Applications</label>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:6px; max-height:160px; overflow-y:auto; padding:8px; border:1px solid var(--border-subtle); border-radius:var(--radius-sm); background:var(--bg-glass);">
          <?php foreach ($apps as $a): ?>
            <label style="display:flex; align-items:center; gap:6px; font-size:0.78rem; color:var(--text-secondary); cursor:pointer;">
              <input type="checkbox" name="app_ids[]" value="<?= esc($a['id']) ?>" class="pol-app-chk">
              <?= esc($a['name']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="display:flex; gap:16px; margin-bottom:16px;">
        <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.8rem; cursor:pointer;">
          <input type="checkbox" name="log_enabled" id="pol_log_enabled" value="1" checked> Log Matching Packets
        </label>
        <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.8rem; cursor:pointer;">
          <input type="checkbox" name="enabled" id="pol_enabled" value="1" checked> Rule Enabled
        </label>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid var(--border-subtle); padding-top:14px;">
        <button type="button" class="btn btn-ghost" onclick="closePolicyModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Policy Rule</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MODAL: ADD CUSTOM SIGNATURE                                 -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div id="sigModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="card card-glass animate-in" style="width:90%; max-width:550px; padding:0; border:1px solid var(--border-subtle); box-shadow:0 12px 40px rgba(0,0,0,0.6);">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-subtle); display:flex; justify-content:space-between; align-items:center;">
      <h3 style="font-size:1.1rem; font-weight:700; margin:0; color:var(--text-primary);">Add Custom DPI Signature</h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeSigModal()">&times;</button>
    </div>

    <form method="POST" action="<?= base_url('appid/signature/save') ?>" id="sigForm" style="padding:20px;">
      <?= csrf_field() ?>

      <div style="margin-bottom:14px;">
        <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--text-primary); margin-bottom:4px;">Signature Name</label>
        <input type="text" name="name" required placeholder="e.g. Internal-HRMS-Protocol" class="form-control" style="width:100%; padding:8px 12px; background:var(--bg-glass); border:1px solid var(--border-subtle); border-radius:var(--radius-sm); color:var(--text-primary);">
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
        <div>
          <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--text-primary); margin-bottom:4px;">Pattern Type</label>
          <select name="pattern_type" class="form-control" style="width:100%; padding:8px 12px; background:var(--bg-glass); border:1px solid var(--border-subtle); border-radius:var(--radius-sm); color:var(--text-primary);">
            <option value="regex">Regular Expression (PCRE)</option>
            <option value="host">Host / SNI Pattern</option>
            <option value="payload_hex">Hex Payload Prefix</option>
          </select>
        </div>
        <div>
          <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--text-primary); margin-bottom:4px;">Transport Protocol</label>
          <select name="protocol" class="form-control" style="width:100%; padding:8px 12px; background:var(--bg-glass); border:1px solid var(--border-subtle); border-radius:var(--radius-sm); color:var(--text-primary);">
            <option value="tcp">TCP</option>
            <option value="udp">UDP</option>
            <option value="any">Any (TCP/UDP)</option>
          </select>
        </div>
      </div>

      <div style="margin-bottom:14px;">
        <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--text-primary); margin-bottom:4px;">Match Pattern / Expression</label>
        <textarea name="pattern" rows="3" required placeholder="^GET /internal-api/.* or *.corp.domain" class="form-control" style="width:100%; font-family:var(--font-mono); font-size:0.8rem; padding:8px 12px; background:var(--bg-glass); border:1px solid var(--border-subtle); border-radius:var(--radius-sm); color:var(--text-primary);"></textarea>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid var(--border-subtle); padding-top:14px;">
        <button type="button" class="btn btn-ghost" onclick="closeSigModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Signature</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MODAL: PREVIEW RULESET                                      -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div id="previewModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="card card-glass animate-in" style="width:90%; max-width:850px; max-height:85vh; overflow-y:auto; padding:0; border:1px solid var(--border-subtle); box-shadow:0 12px 40px rgba(0,0,0,0.6);">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-subtle); display:flex; justify-content:space-between; align-items:center;">
      <h3 style="font-size:1.1rem; font-weight:700; margin:0; color:var(--text-primary);">Compiled nDPI &amp; nftables Ruleset Preview</h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closePreviewModal()">&times;</button>
    </div>

    <div style="padding:20px;">
      <h4 style="font-size:0.85rem; font-weight:700; color:var(--color-cyan); margin:0 0 6px 0;">/etc/ndpi/ndpi_rules.conf</h4>
      <pre id="previewNdpi" style="background:#090d16; border:1px solid var(--border-subtle); border-radius:var(--radius-sm); padding:12px; font-size:0.75rem; color:#a5b4fc; max-height:220px; overflow-y:auto;"></pre>

      <h4 style="font-size:0.85rem; font-weight:700; color:var(--color-indigo, #6366f1); margin:16px 0 6px 0;">nftables dpi_forward Hook Snippet</h4>
      <pre id="previewNft" style="background:#090d16; border:1px solid var(--border-subtle); border-radius:var(--radius-sm); padding:12px; font-size:0.75rem; color:#a7f3d0; max-height:220px; overflow-y:auto;"></pre>
    </div>

    <div style="display:flex; justify-content:flex-end; padding:12px 20px; border-top:1px solid var(--border-subtle);">
      <button type="button" class="btn btn-primary" onclick="closePreviewModal()">Close Preview</button>
    </div>
  </div>
</div>

<script>
// Tab Switching
function switchDpiTab(tabId) {
  document.querySelectorAll('.dpi-tab-btn').forEach(btn => btn.classList.remove('active', 'btn-primary'));
  document.querySelectorAll('.dpi-tab-btn').forEach(btn => btn.classList.add('btn-ghost'));
  document.querySelectorAll('.dpi-tab-content').forEach(c => c.style.display = 'none');

  const activeBtn = document.getElementById('tabBtn-' + tabId);
  const activeContent = document.getElementById('tabContent-' + tabId);

  if (activeBtn) {
    activeBtn.classList.remove('btn-ghost');
    activeBtn.classList.add('active', 'btn-primary');
  }
  if (activeContent) {
    activeContent.style.display = 'block';
  }

  if (tabId === 'live') {
    fetchLiveFlows();
  }
}

// Live Flow Telemetry Poller
let flowTimer = null;
function fetchLiveFlows() {
  MicronScheduler.fetch('<?= base_url('appid/live-flows') ?>')
    .then(r => r.json())
    .then(data => {
      if (!data.success || !data.flows) return;
      const tbody = document.getElementById('liveFlowsBody');
      if (!tbody) return;

      if (data.flows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="padding:30px; text-align:center; color:var(--text-muted);">No active flows detected at this moment.</td></tr>';
        return;
      }

      tbody.innerHTML = data.flows.map(f => {
        let actionBadge = '<span class="badge badge-emerald">ALLOW</span>';
        if (f.action === 'block') {
          actionBadge = '<span class="badge badge-red">BLOCKED</span>';
        } else if (f.action === 'shape') {
          actionBadge = '<span class="badge badge-amber">SHAPED</span>';
        }

        let riskColor = '#10b981';
        if (f.risk_level >= 5) riskColor = '#ef4444';
        else if (f.risk_level >= 4) riskColor = '#f59e0b';
        else if (f.risk_level >= 3) riskColor = '#f59e0b';
        else if (f.risk_level >= 2) riskColor = '#06b6d4';

        return `
          <tr style="border-bottom:1px solid var(--border-subtle); font-size:0.82rem;">
            <td style="padding:10px 16px; font-family:var(--font-mono); color:var(--text-primary);">${f.src_ip}:${f.src_port}</td>
            <td style="padding:10px 16px; font-family:var(--font-mono); color:var(--text-secondary);">${f.dst_ip}:${f.dst_port}</td>
            <td style="padding:10px 16px; font-family:var(--font-mono); color:var(--color-cyan);">${f.proto}</td>
            <td style="padding:10px 16px; font-weight:600; color:var(--text-primary);">${f.app_name}</td>
            <td style="padding:10px 16px; text-transform:capitalize; color:var(--text-secondary);">${f.category}</td>
            <td style="padding:10px 16px;">
              <span class="badge" style="background:${riskColor}22; color:${riskColor}; border:1px solid ${riskColor}44; font-size:0.7rem;">
                Risk ${f.risk_level}
              </span>
            </td>
            <td style="padding:10px 16px; font-family:var(--font-mono); color:var(--text-primary); font-weight:600;">${f.throughput}</td>
            <td style="padding:10px 16px;">${actionBadge}</td>
          </tr>
        `;
      }).join('');
    })
    .catch(err => console.error('Error fetching live flows:', err));
}

function toggleAutoRefresh(enabled) {
  if (enabled) {
    if (!flowTimer) flowTimer = MicronScheduler.poll('dpi.live_flows', { interval: 3000, run: fetchLiveFlows });
  } else {
    if (flowTimer) {
      flowTimer.stop();
      flowTimer = null;
    }
  }
}

// Start auto-refresh by default via MicronScheduler
flowTimer = MicronScheduler.poll('dpi.live_flows', { interval: 3000, run: fetchLiveFlows });

// Application Catalogue Filtering
function filterAppCatalogue() {
  const query = document.getElementById('appFilterInput').value.toLowerCase().trim();
  document.querySelectorAll('.app-row').forEach(row => {
    const name = row.getAttribute('data-name');
    row.style.display = name.includes(query) ? '' : 'none';
  });
}

function filterByCat(catId, btn) {
  document.querySelectorAll('.app-cat-pill').forEach(b => b.classList.remove('active', 'btn-primary'));
  btn.classList.add('active', 'btn-primary');

  document.querySelectorAll('.app-row').forEach(row => {
    if (catId === 'all' || row.getAttribute('data-cat') === catId) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
}

// Modal Handlers
function openPolicyModal() {
  document.getElementById('policyForm').reset();
  document.getElementById('pol_id').value = '';
  document.getElementById('policyModalTitle').innerText = 'Create Application Policy';
  document.querySelectorAll('.pol-cat-chk, .pol-app-chk').forEach(c => c.checked = false);
  document.getElementById('bandwidthField').style.display = 'none';
  document.getElementById('policyModal').style.display = 'flex';
}

function closePolicyModal() {
  document.getElementById('policyModal').style.display = 'none';
}

function toggleBandwidthField(val) {
  document.getElementById('bandwidthField').style.display = (val === 'shape') ? 'block' : 'none';
}

function editPolicy(pol) {
  document.getElementById('policyForm').reset();
  document.getElementById('pol_id').value = pol.id;
  document.getElementById('pol_name').value = pol.name;
  document.getElementById('pol_src_interface').value = pol.src_interface;
  document.getElementById('pol_dst_interface').value = pol.dst_interface;
  document.getElementById('policyModalTitle').innerText = 'Edit Application Policy';

  const actionRadios = document.getElementsByName('action');
  for (let r of actionRadios) {
    if (r.value === pol.action) r.checked = true;
  }
  toggleBandwidthField(pol.action);

  if (pol.bandwidth_limit_kbps) {
    document.getElementById('pol_bandwidth_limit_kbps').value = pol.bandwidth_limit_kbps;
  }

  // Pre-check apps and categories
  const appIds = pol.app_ids ? JSON.parse(pol.app_ids) : [];
  const catIds = pol.category_ids ? JSON.parse(pol.category_ids) : [];

  document.querySelectorAll('.pol-cat-chk').forEach(chk => {
    chk.checked = catIds.includes(chk.value);
  });
  document.querySelectorAll('.pol-app-chk').forEach(chk => {
    chk.checked = appIds.includes(chk.value);
  });

  document.getElementById('pol_log_enabled').checked = !!parseInt(pol.log_enabled);
  document.getElementById('pol_enabled').checked = !!parseInt(pol.enabled);

  document.getElementById('policyModal').style.display = 'flex';
}

// Save Policy AJAX
document.getElementById('policyForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const formData = new FormData(this);
  MicronScheduler.fetch('<?= base_url('appid/policy/save') ?>', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      location.reload();
    } else {
      alert(data.message || 'Error saving policy.');
    }
  });
});

// Delete Policy
function deletePolicy(id, name) {
  if (!confirm(`Are you sure you want to delete policy "${name}"?`)) return;
  MicronScheduler.fetch(`<?= base_url('appid/policy') ?>/${id}/delete`, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) location.reload();
    else alert(data.message || 'Error deleting policy.');
  });
}

// Toggle Policy
function togglePolicy(id) {
  MicronScheduler.fetch(`<?= base_url('appid/policy') ?>/${id}/toggle`, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) location.reload();
    else alert(data.message || 'Error toggling policy.');
  });
}

// Custom Signatures
function openSigModal() {
  document.getElementById('sigForm').reset();
  document.getElementById('sigModal').style.display = 'flex';
}
function closeSigModal() {
  document.getElementById('sigModal').style.display = 'none';
}

document.getElementById('sigForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const formData = new FormData(this);
  MicronScheduler.fetch('<?= base_url('appid/signature/save') ?>', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) location.reload();
    else alert(data.message || 'Error saving signature.');
  });
});

function deleteSignature(id, name) {
  if (!confirm(`Delete custom signature "${name}"?`)) return;
  MicronScheduler.fetch(`<?= base_url('appid/signature') ?>/${id}/delete`, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) location.reload();
    else alert(data.message || 'Error deleting signature.');
  });
}

// Settings AJAX
document.getElementById('dpiSettingsForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const formData = new FormData(this);
  MicronScheduler.fetch('<?= base_url('appid/settings/save') ?>', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) alert(data.message);
    else alert(data.message || 'Error saving settings.');
  });
});

// Preview Modal
function openPreviewModal() {
  MicronScheduler.fetch('<?= base_url('appid/preview') ?>')
    .then(r => r.json())
    .then(data => {
      document.getElementById('previewNdpi').textContent = data.ndpi_config;
      document.getElementById('previewNft').textContent = data.nftables_snippet;
      document.getElementById('previewModal').style.display = 'flex';
    });
}
function closePreviewModal() {
  document.getElementById('previewModal').style.display = 'none';
}

// Apply DPI Rules
function applyDpiRules() {
  const form = document.getElementById('applyDpiForm');
  const formData = new FormData(form);
  MicronScheduler.fetch(form.action, {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      alert(data.message);
      location.reload();
    } else {
      alert(data.message || 'Error applying DPI rules.');
    }
  });
}
</script>

<?= $this->endSection() ?>
