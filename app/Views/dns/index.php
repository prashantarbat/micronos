<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.dns-tabs {
  display: flex;
  gap: 2px;
  border-bottom: 2px solid var(--color-border);
  margin-bottom: 24px;
  flex-wrap: wrap;
}
.dns-tab {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--text-muted);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: all .15s ease;
  background: none;
  border-top: none;
  border-left: none;
  border-right: none;
}
.dns-tab:hover { color: var(--text-primary); }
.dns-tab.active { color: var(--color-cyan); border-bottom-color: var(--color-cyan); }
.dns-tab-count {
  background: rgba(255,255,255,0.08);
  border-radius: 10px;
  padding: 1px 7px;
  font-size: 0.68rem;
  font-weight: 700;
}
.dns-tab.active .dns-tab-count { background: rgba(6,182,212,0.2); color: var(--color-cyan); }
.dns-panel { display: none; }
.dns-panel.active { display: block; }
</style>

<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <h1 class="page-title">DNS Resolver &amp; DNSBL Threat Shield</h1>
      <p class="page-subtitle">Unbound recursive DNS with DNS-over-TLS (DoT), cryptographic DNSSEC validation, and threat-feed blocking</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
      <?= \App\Libraries\ServiceStatus::widget('unbound') ?>
      <form method="POST" action="<?= base_url('dns/feeds/sync') ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-secondary btn-sm">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Sync DNSBL Feeds
        </button>
      </form>
      <?php if (session()->get('is_superadmin') || in_array('dns.flush', session()->get('permissions') ?? [])): ?>
        <form method="POST" action="<?= base_url('dns/flush') ?>">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost btn-sm">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
            Flush Cache
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Key DNS Metrics -->
<div class="stats-grid animate-in animate-in-delay-1" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); margin-bottom:24px;">
  <div class="stat-card cyan">
    <div class="stat-value"><?= number_format($stats['total_queries']) ?></div>
    <div class="stat-label">Total Queries (24h)</div>
  </div>
  <div class="stat-card emerald">
    <div class="stat-value"><?= esc($stats['cache_hit_ratio']) ?></div>
    <div class="stat-label">Cache Hit Ratio</div>
  </div>
  <div class="stat-card red">
    <div class="stat-value"><?= number_format($stats['blocked_queries']) ?> <span style="font-size:0.8rem; font-weight:500; opacity:0.8;">(<?= esc($stats['blocked_percentage']) ?>)</span></div>
    <div class="stat-label">DNSBL Blocked Threats</div>
  </div>
  <div class="stat-card purple">
    <div class="stat-value"><?= number_format($stats['total_blocked_rules']) ?></div>
    <div class="stat-label">Active Blocklist Signatures</div>
  </div>
</div>

<!-- Tab Navigation -->
<div class="dns-tabs animate-in animate-in-delay-2">
  <button type="button" class="dns-tab active" onclick="switchDnsTab('inspector', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
    </svg>
    DNSBL Telemetry &amp; Logs
    <span class="dns-tab-count"><?= count($recentBlocked) ?></span>
  </button>

  <button type="button" class="dns-tab" onclick="switchDnsTab('feeds', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
    </svg>
    Feed Subscriptions
    <span class="dns-tab-count"><?= count($feeds) ?></span>
  </button>

  <button type="button" class="dns-tab" onclick="switchDnsTab('rules', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
    </svg>
    Whitelist &amp; Blacklist
    <span class="dns-tab-count"><?= count($whitelist) + count($blacklist) ?></span>
  </button>

  <button type="button" class="dns-tab" onclick="switchDnsTab('hosts', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
    </svg>
    Host Overrides
    <span class="dns-tab-count"><?= count($hosts) ?></span>
  </button>

  <button type="button" class="dns-tab" onclick="switchDnsTab('settings', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
    </svg>
    Resolver &amp; DoT
  </button>
</div>

<!-- ============================================================
     TAB 1: DNSBL Telemetry & Live Query Inspector
============================================================ -->
<div class="dns-panel active" id="panel-inspector">
  <div class="card card-glass animate-in animate-in-delay-3" style="margin-bottom:28px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="card-title">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        Recent Intercepted DNS Queries (DNSBL Live Feed)
      </span>
      <span class="badge badge-emerald">Live Interception Active</span>
    </div>
    <div style="overflow-x:auto;">
      <table class="iface-table">
        <thead>
          <tr>
            <th>Time</th>
            <th>Client IP</th>
            <th>Queried Domain</th>
            <th>Record</th>
            <th>Triggering Threat Feed</th>
            <th>Sinkhole Action</th>
            <th style="text-align:right;">Quick Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentBlocked as $q): ?>
            <tr>
              <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-muted);"><?= esc($q['time']) ?></td>
              <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--color-cyan);"><?= esc($q['client']) ?></td>
              <td style="font-family:var(--font-mono); font-weight:600; color:var(--color-red);"><?= esc($q['domain']) ?></td>
              <td><span class="badge badge-muted" style="font-size:0.7rem;"><?= esc($q['type']) ?></span></td>
              <td><span class="badge badge-purple" style="font-size:0.75rem;"><?= esc($q['feed']) ?></span></td>
              <td><span class="badge badge-red" style="font-size:0.75rem;">BLOCKED &rarr; <?= esc($settings['blocked_query_action']) ?></span></td>
              <td style="text-align:right;">
                <form method="POST" action="<?= base_url('dns/overrides/quick-whitelist') ?>" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="domain" value="<?= esc($q['domain']) ?>">
                  <button type="submit" class="btn btn-ghost btn-sm" title="Whitelist this domain" style="font-size:0.72rem; padding:4px 8px; color:var(--color-emerald);">
                    + Whitelist
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ============================================================
     TAB 2: Feed Subscriptions
============================================================ -->
<div class="dns-panel" id="panel-feeds">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
    <div>
      <h3 style="margin:0; font-size:1rem; font-weight:700; color:var(--text-primary);">Subscribed Threat &amp; Ad Feeds</h3>
      <p style="margin:2px 0 0; font-size:0.8rem; color:var(--text-muted);">Curated community domain lists compiled into Unbound high-speed memory bloom filter</p>
    </div>
    <button type="button" class="btn btn-primary btn-sm" onclick="openAddFeedModal()">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
      </svg>
      Add Custom Feed
    </button>
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:16px; margin-bottom:28px;">
    <?php foreach ($feeds as $f): ?>
      <div class="card card-glass" style="margin:0; padding:14px 16px; display:flex; flex-direction:column; justify-content:space-between;">
        <div>
          <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
            <div style="font-weight:700; font-size:0.9rem; color:var(--text-primary);"><?= esc($f['name']) ?></div>
            <span class="badge <?= $f['enabled'] ? 'badge-emerald' : 'badge-muted' ?>">
              <?= $f['enabled'] ? 'Active' : 'Disabled' ?>
            </span>
          </div>
          <div style="font-size:0.75rem; color:var(--text-secondary); margin-bottom:8px;">
            Category: <strong style="color:var(--color-cyan);"><?= esc($f['category']) ?></strong>
          </div>
          <div style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-muted); word-break:break-all; background:rgba(0,0,0,0.25); border:1px solid var(--color-border); padding:6px 8px; border-radius:4px; margin-bottom:10px;">
            <?= esc($f['url']) ?>
          </div>
        </div>
        <div>
          <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; color:var(--text-muted); padding-top:10px; border-top:1px solid var(--color-border);">
            <span>Entries: <strong style="color:var(--text-primary);"><?= number_format($f['entries_count']) ?></strong></span>
            <div style="display:flex; gap:6px;">
              <form method="POST" action="<?= base_url('dns/feeds/' . esc($f['id']) . '/toggle') ?>" style="display:inline;">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:3px 7px;">
                  <?= $f['enabled'] ? 'Disable' : 'Enable' ?>
                </button>
              </form>
              <form method="POST" action="<?= base_url('dns/feeds/' . esc($f['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove feed subscription?')">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:3px 7px; color:var(--color-red);">
                  Delete
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ============================================================
     TAB 3: Whitelist & Blacklist Overrides
============================================================ -->
<div class="dns-panel" id="panel-rules">
  <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:28px;">
    <!-- Whitelist -->
    <div class="card card-glass" style="margin:0;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title" style="color:var(--color-emerald);">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          Domain Whitelist (Bypass Blocklists)
        </span>
        <button type="button" class="btn btn-primary btn-sm" onclick="openAddRuleModal('whitelist')">+ Add Whitelist</button>
      </div>
      <div style="overflow-x:auto;">
        <table class="iface-table">
          <thead>
            <tr><th>Domain</th><th>Wildcard</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php if (empty($whitelist)): ?>
              <tr><td colspan="3" style="text-align:center; padding:20px; color:var(--text-muted);">No domains whitelisted.</td></tr>
            <?php else: ?>
              <?php foreach ($whitelist as $w): ?>
                <tr>
                  <td style="font-family:var(--font-mono); font-weight:600; color:var(--color-emerald);"><?= esc($w['domain']) ?></td>
                  <td><?= $w['is_wildcard'] ? '<span class="badge badge-cyan">Subdomains</span>' : '<span class="badge badge-muted">Exact</span>' ?></td>
                  <td style="text-align:right;">
                    <form method="POST" action="<?= base_url('dns/overrides/' . esc($w['id']) . '/delete') ?>" style="display:inline;">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-red); padding:3px 7px;">Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Blacklist -->
    <div class="card card-glass" style="margin:0;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title" style="color:var(--color-red);">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
          </svg>
          Domain Blacklist (Custom Blocks)
        </span>
        <button type="button" class="btn btn-primary btn-sm" onclick="openAddRuleModal('blacklist')">+ Add Blacklist</button>
      </div>
      <div style="overflow-x:auto;">
        <table class="iface-table">
          <thead>
            <tr><th>Domain</th><th>Wildcard</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php if (empty($blacklist)): ?>
              <tr><td colspan="3" style="text-align:center; padding:20px; color:var(--text-muted);">No domains blacklisted.</td></tr>
            <?php else: ?>
              <?php foreach ($blacklist as $b): ?>
                <tr>
                  <td style="font-family:var(--font-mono); font-weight:600; color:var(--color-red);"><?= esc($b['domain']) ?></td>
                  <td><?= $b['is_wildcard'] ? '<span class="badge badge-cyan">Subdomains</span>' : '<span class="badge badge-muted">Exact</span>' ?></td>
                  <td style="text-align:right;">
                    <form method="POST" action="<?= base_url('dns/overrides/' . esc($b['id']) . '/delete') ?>" style="display:inline;">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-red); padding:3px 7px;">Remove</button>
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
     TAB 4: Local DNS Host Overrides
============================================================ -->
<div class="dns-panel" id="panel-hosts">
  <div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:28px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="card-title">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
        </svg>
        Local DNS Host Overrides (Split-Horizon / A Records)
      </span>
      <button type="button" class="btn btn-primary btn-sm" onclick="openAddHostModal()">+ Add Host Override</button>
    </div>
    <div style="overflow-x:auto;">
      <table class="iface-table">
        <thead>
          <tr>
            <th>Hostname / FQDN</th>
            <th>Mapped IP Address</th>
            <th>Description</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($hosts)): ?>
            <tr>
              <td colspan="4" style="text-align:center; padding:32px; color:var(--text-muted);">
                No custom local DNS overrides defined. Add an internal host entry above.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($hosts as $h): ?>
              <tr>
                <td style="font-family:var(--font-mono); font-weight:600; color:var(--text-primary);"><?= esc($h['hostname']) ?></td>
                <td style="font-family:var(--font-mono); color:var(--color-cyan);"><?= esc($h['ip_address']) ?></td>
                <td style="font-size:0.8rem; color:var(--text-secondary);"><?= esc($h['description'] ?: '—') ?></td>
                <td style="text-align:right; white-space:nowrap;">
                  <button type="button" class="btn btn-ghost btn-sm" onclick='openEditHostModal(<?= json_encode($h, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Edit DNS Host" style="padding:4px 8px;">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </button>
                  <form method="POST" action="<?= base_url('dns/hosts/' . esc($h['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove DNS override for <?= esc(addslashes($h['hostname'])) ?>?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger btn-sm" title="Delete Host" style="padding:4px 8px;">
                      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                      </svg>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Domain Overrides -->
  <div class="card card-glass animate-in animate-in-delay-3" style="margin-bottom:28px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="card-title">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4m7 14l5-5-5-5m5 5H9"/>
        </svg>
        Domain Overrides (Per-Domain Forwarding)
      </span>
      <button type="button" class="btn btn-primary btn-sm" onclick="openAddDomainOverrideModal()">+ Add Domain Override</button>
    </div>
    <div style="overflow-x:auto;">
      <table class="iface-table">
        <thead>
          <tr>
            <th>Domain / Zone</th>
            <th>Forward DNS Server</th>
            <th>Description</th>
            <th>Status</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($domainOverrides)): ?>
            <tr>
              <td colspan="5" style="text-align:center; padding:32px; color:var(--text-muted);">
                No domain overrides defined. Forward an entire zone (e.g. corp.internal) to a dedicated authoritative resolver.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($domainOverrides as $d): ?>
              <tr>
                <td style="font-family:var(--font-mono); font-weight:600; color:var(--color-cyan);"><?= esc($d['domain']) ?>.</td>
                <td style="font-family:var(--font-mono); color:var(--text-primary);">
                  <?= esc($d['dns_server']) ?>
                  <?php if ((int) $d['port'] !== 53): ?>
                    <span class="badge badge-muted" style="font-size:0.68rem;">:<?= (int) $d['port'] ?></span>
                  <?php endif; ?>
                </td>
                <td style="font-size:0.8rem; color:var(--text-secondary);"><?= esc($d['description'] ?: '—') ?></td>
                <td><?= ((int) $d['enabled'] === 1) ? '<span class="badge badge-emerald">Enabled</span>' : '<span class="badge badge-muted">Disabled</span>' ?></td>
                <td style="text-align:right; white-space:nowrap;">
                  <form method="POST" action="<?= base_url('dns/domain-overrides/' . esc($d['id']) . '/toggle') ?>" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px;">
                      <?= (int) $d['enabled'] === 1 ? 'Disable' : 'Enable' ?>
                    </button>
                  </form>
                  <button type="button" class="btn btn-ghost btn-sm" onclick='openEditDomainOverrideModal(<?= json_encode($d, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Edit Domain Override" style="padding:4px 8px;">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </button>
                  <form method="POST" action="<?= base_url('dns/domain-overrides/' . esc($d['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove domain override for <?= esc(addslashes($d['domain'])) ?>?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger btn-sm" title="Delete Domain Override" style="padding:4px 8px;">
                      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                      </svg>
                    </button>
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

<!-- ============================================================
     TAB 5: Resolver & DNS-over-TLS (DoT) Settings
============================================================ -->
<div class="dns-panel" id="panel-settings">
  <div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:28px;">
    <div class="card-header">
      <span class="card-title">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
        </svg>
        Unbound Resolver &amp; Privacy Cryptography Settings
      </span>
    </div>
    <div class="card-body">
      <form method="POST" action="<?= base_url('dns/settings') ?>">
        <?= csrf_field() ?>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
          <!-- DNSSEC -->
          <div style="background:rgba(255,255,255,0.02); border:1px solid var(--color-border); padding:16px; border-radius:var(--radius-md);">
            <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:600; color:var(--text-primary); margin-bottom:6px;">
              <input type="checkbox" name="enable_dnssec" value="1" <?= !empty($settings['enable_dnssec']) ? 'checked' : '' ?> style="width:16px; height:16px; accent-color:var(--color-cyan);">
              Enable DNSSEC Validation
            </label>
            <p style="font-size:0.75rem; color:var(--text-muted); margin:0;">
              Cryptographically authenticate root trust anchors to protect against DNS cache poisoning and man-in-the-middle spoofing attacks.
            </p>
          </div>

          <!-- DNSBL -->
          <div style="background:rgba(255,255,255,0.02); border:1px solid var(--color-border); padding:16px; border-radius:var(--radius-md);">
            <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:600; color:var(--text-primary); margin-bottom:6px;">
              <input type="checkbox" name="enable_dnsbl" value="1" <?= !empty($settings['enable_dnsbl']) ? 'checked' : '' ?> style="width:16px; height:16px; accent-color:var(--color-cyan);">
              Enable DNSBL Ad &amp; Threat Blocker
            </label>
            <p style="font-size:0.75rem; color:var(--text-muted); margin:0;">
              Intercept malicious domains, malware command-and-control, trackers, and telemetry before network traffic ever leaves the appliance.
            </p>
          </div>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:20px; margin-bottom:20px;">
          <!-- Upstream Mode -->
          <div class="form-group">
            <label class="form-label" for="upstream_mode">Upstream Resolution Mode</label>
            <select name="upstream_mode" id="upstream_mode" class="form-control">
              <option value="dot" <?= ($settings['upstream_mode'] ?? '') === 'dot' ? 'selected' : '' ?>>DNS-over-TLS (DoT port 853 - Encrypted &amp; Private)</option>
              <option value="standard" <?= ($settings['upstream_mode'] ?? '') === 'standard' ? 'selected' : '' ?>>Direct Recursive Root (UDP/TCP port 53)</option>
            </select>
            <small style="color:var(--text-muted); font-size:0.72rem;">DoT encrypts all outbound DNS queries from ISPs and surveillance.</small>
          </div>

          <!-- Block Action -->
          <div class="form-group">
            <label class="form-label" for="blocked_query_action">DNSBL Sinkhole Response</label>
            <select name="blocked_query_action" id="blocked_query_action" class="form-control">
              <option value="0.0.0.0" <?= ($settings['blocked_query_action'] ?? '') === '0.0.0.0' ? 'selected' : '' ?>>Sinkhole to 0.0.0.0 (Fastest drop)</option>
              <option value="nxdomain" <?= ($settings['blocked_query_action'] ?? '') === 'nxdomain' ? 'selected' : '' ?>>NXDOMAIN (Domain Not Found)</option>
            </select>
          </div>

          <!-- Min TTL -->
          <div class="form-group">
            <label class="form-label" for="cache_min_ttl">Minimum Cache TTL (seconds)</label>
            <input type="number" name="cache_min_ttl" id="cache_min_ttl" class="form-control" value="<?= esc($settings['cache_min_ttl'] ?? 60) ?>">
          </div>

          <!-- Max TTL -->
          <div class="form-group">
            <label class="form-label" for="cache_max_ttl">Maximum Cache TTL (seconds)</label>
            <input type="number" name="cache_max_ttl" id="cache_max_ttl" class="form-control" value="<?= esc($settings['cache_max_ttl'] ?? 86400) ?>">
          </div>
        </div>

        <div style="text-align:right;">
          <button type="submit" class="btn btn-primary">Save &amp; Reload Unbound Resolver</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================
       Appliance DNS Resolution Behavior (how the FIREWALL resolves)
  ============================================================ -->
  <div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:28px;">
    <div class="card-header">
      <span class="card-title">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
        DNS Resolution Behavior — How the Firewall Resolves DNS for Itself
      </span>
    </div>
    <div class="card-body">
      <p style="font-size:0.78rem; color:var(--text-muted); margin:0 0 20px;">
        These settings fine-tune the way the firewall utilizes DNS servers <strong>for its own needs</strong> —
        system updates, package and plugin installs, NTP and time sync. Name resolution for LAN clients is
        unaffected: those queries are answered by the Unbound resolver configured above.
      </p>

      <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:20px; padding:12px 14px; background:rgba(255,255,255,0.02); border:1px solid var(--color-border); border-radius:var(--radius-md);">
        <span style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.08em; color:var(--text-muted);">Currently in effect:</span>
        <?php foreach (($resolver['servers'] ?? []) as $ns): ?>
          <code style="font-size:0.75rem; padding:2px 8px; border-radius:999px; background:var(--color-cyan-dim); color:var(--color-cyan);"><?= esc($ns) ?></code>
        <?php endforeach; ?>
        <?php if (($resolver['servers'] ?? []) === []): ?>
          <span class="badge badge-red" style="font-size:0.72rem;">No nameserver would be configured</span>
        <?php endif; ?>
        <span style="font-size:0.72rem; color:var(--text-muted); margin-left:auto;">applied to <code><?= esc($resolver['file'] ?? '') ?></code></span>
      </div>

      <form method="POST" action="<?= base_url('dns/resolver') ?>">
        <?= csrf_field() ?>

        <!-- DNS Server Override -->
        <div style="display:grid; grid-template-columns:200px 1fr; gap:18px; padding:16px 0; border-top:1px solid var(--color-border);">
          <label class="form-label" style="margin:0;">DNS Server Override</label>
          <div>
            <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; font-weight:600; color:var(--text-primary);">
              <input type="checkbox" name="dns_server_override" value="1"
                     <?= !empty($resolver['override']) ? 'checked' : '' ?>
                     style="width:16px; height:16px; margin-top:2px; accent-color:var(--color-cyan); flex:none;">
              <span>Allow DNS server list to be overridden by DHCP/PPP on WAN or remote OpenVPN server</span>
            </label>
            <p class="form-hint" style="margin-top:8px;">
              When checked, the firewall will use DNS servers assigned by a DHCP/PPP server on WAN or by a remote
              OpenVPN server (when Pull DNS is enabled) for its own purposes, overriding those set manually. Those
              servers are never handed to DHCP clients. To force the use of only the DNS servers on this page,
              uncheck this option. This does not apply to the DNS Resolver, which always resolves for clients from
              its own configuration.
            </p>
            <?php if (!empty($resolver['override']) && ($resolver['behavior'] ?? '') === 'local-only'): ?>
              <p class="form-hint" style="margin-top:6px; color:var(--color-amber,#f5a524);">
                This override has no effect while remote DNS Servers are ignored (local DNS only).
              </p>
            <?php endif; ?>

            <?php $dynamicStored = $resolver['dynamic_stored'] ?? []; ?>
            <?php if ($dynamicStored !== []): ?>
              <p class="form-hint" style="margin-top:10px;">
                Dynamically assigned:
                <?php foreach ($dynamicStored as $ip): ?>
                  <code style="font-size:0.72rem; padding:2px 8px; border-radius:999px; background:rgba(255,255,255,0.06); color:var(--text-primary);"><?= esc($ip) ?></code>
                <?php endforeach; ?>
                <?php if (empty($resolver['override'])): ?>
                  — ignored while this override is unchecked.
                <?php elseif (($resolver['behavior'] ?? '') === 'local-only'): ?>
                  — not used while remote DNS Servers are ignored.
                <?php else: ?>
                  — merged ahead of the configured servers.
                <?php endif; ?>
              </p>
            <?php elseif (!empty($resolver['override']) && ($resolver['behavior'] ?? '') !== 'local-only'): ?>
              <p class="form-hint" style="margin-top:10px; color:var(--text-muted);">
                No dynamically assigned DNS servers are present. They appear here after a DHCP/PPP WAN link
                or a remote OpenVPN server with Pull DNS assigns one, and are applied when the configuration
                is next saved or an interface is applied.
              </p>
            <?php endif; ?>
          </div>
        </div>

        <!-- DNS Resolution Behavior -->
        <div style="display:grid; grid-template-columns:200px 1fr; gap:18px; padding:16px 0; border-top:1px solid var(--color-border);">
          <label class="form-label" for="dns_resolution_behavior" style="margin:0;">DNS Resolution Behavior</label>
          <div>
            <select name="dns_resolution_behavior" id="dns_resolution_behavior" class="form-control" onchange="showResolverHelp(this.value)">
              <?php foreach (($resolverBehaviors ?? []) as $value): ?>
                <option value="<?= esc($value, 'attr') ?>" <?= ($resolver['behavior'] ?? '') === $value ? 'selected' : '' ?>>
                  <?= esc(\App\Libraries\SystemResolverCompiler::label($value)) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="form-hint" style="margin-top:8px;">
              This option controls how the firewall itself resolves DNS queries.
            </p>

            <?php foreach (($resolverBehaviors ?? []) as $value): ?>
              <p class="form-hint resolver-help" data-behavior="<?= esc($value, 'attr') ?>"
                 style="margin-top:8px; padding:10px 12px; border-left:2px solid var(--color-cyan-dim); background:rgba(255,255,255,0.02); <?= ($resolver['behavior'] ?? '') === $value ? '' : 'display:none;' ?>">
                <strong style="color:var(--text-primary);"><?= esc(\App\Libraries\SystemResolverCompiler::label($value)) ?></strong><br>
                <?= esc(\App\Libraries\SystemResolverCompiler::help($value)) ?>
              </p>
            <?php endforeach; ?>
          </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px; padding-top:16px; border-top:1px solid var(--color-border);">
          <button type="submit" class="btn btn-primary">Save &amp; Apply DNS Resolution Behavior</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Feed Modal -->
<div id="addFeedModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:540px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1rem; font-weight:700;">Subscribe to DNSBL Feed</h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeAddFeedModal()">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('dns/feeds/store') ?>">
      <?= csrf_field() ?>
      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label">Feed Name</label>
        <input type="text" name="name" class="form-control" required placeholder="e.g. Peter Lowe Ad Server List">
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label">Feed Source URL (Hosts or Domain list format)</label>
        <input type="url" name="url" class="form-control" required placeholder="https://pgl.yoyo.org/adservers/serverlist.php?hostformat=hosts">
      </div>
      <div class="form-group" style="margin-bottom:18px;">
        <label class="form-label">Category</label>
        <select name="category" class="form-control">
          <option value="Ads & Tracking">Ads &amp; Tracking</option>
          <option value="Malware & Phishing">Malware &amp; Phishing</option>
          <option value="Telemetry">Telemetry &amp; Spyware</option>
          <option value="Cryptominers">Cryptomining Domains</option>
        </select>
      </div>
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeAddFeedModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Subscribe Feed</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Whitelist/Blacklist Rule Modal -->
<div id="addRuleModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:500px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1rem; font-weight:700;" id="ruleModalTitle">Add Domain Rule</h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeAddRuleModal()">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('dns/overrides/store') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="rule_action" value="whitelist">
      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label">Domain Name</label>
        <input type="text" name="domain" class="form-control" required placeholder="analytics.google.com" style="font-family:var(--font-mono);">
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; color:var(--text-secondary); cursor:pointer;">
          <input type="checkbox" name="is_wildcard" value="1" style="accent-color:var(--color-cyan);">
          Include all subdomains (e.g. *.example.com)
        </label>
      </div>
      <div class="form-group" style="margin-bottom:18px;">
        <label class="form-label">Description / Note</label>
        <input type="text" name="description" class="form-control" placeholder="Optional rationale">
      </div>
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeAddRuleModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Rule</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Host Override Modal -->
<div id="addHostModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:520px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1rem; font-weight:700;">Add Local DNS Host Override</h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeAddHostModal()">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('dns/hosts/store') ?>">
      <?= csrf_field() ?>
      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label">Fully Qualified Domain Name (FQDN)</label>
        <input type="text" name="hostname" class="form-control" required placeholder="gateway.micronos.internal" style="font-family:var(--font-mono);">
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label">Mapped IP Address</label>
        <input type="text" name="ip_address" class="form-control" required placeholder="192.168.1.1" style="font-family:var(--font-mono);">
      </div>
      <div class="form-group" style="margin-bottom:18px;">
        <label class="form-label">Description</label>
        <input type="text" name="description" class="form-control" placeholder="Internal server / router address">
      </div>
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeAddHostModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Host</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Domain Override Modal -->
<div id="addDomainOverrideModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:520px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1rem; font-weight:700;">Add Domain Override</h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeAddDomainOverrideModal()">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('dns/domain-overrides/store') ?>">
      <?= csrf_field() ?>
      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label">Domain / Zone</label>
        <input type="text" name="domain" class="form-control" required placeholder="corp.internal" style="font-family:var(--font-mono);">
      </div>
      <div style="display:grid; grid-template-columns:1fr 120px; gap:12px; margin-bottom:12px;">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Forward DNS Server (IP)</label>
          <input type="text" name="dns_server" class="form-control" required placeholder="10.0.0.53" style="font-family:var(--font-mono);">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Port</label>
          <input type="number" name="port" class="form-control" value="53" min="1" max="65535">
        </div>
      </div>
      <div class="form-group" style="margin-bottom:18px;">
        <label class="form-label">Description</label>
        <input type="text" name="description" class="form-control" placeholder="Internal AD resolver">
      </div>
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeAddDomainOverrideModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Domain Override</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Domain Override Modal -->
<div id="editDomainOverrideModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:520px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1rem; font-weight:700;">Edit Domain Override</h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeEditDomainOverrideModal()">&times;</button>
    </div>
    <form id="editDomainOverrideForm" method="POST" action="">
      <?= csrf_field() ?>
      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label">Domain / Zone</label>
        <input type="text" name="domain" id="edit_do_domain" class="form-control" required style="font-family:var(--font-mono);">
      </div>
      <div style="display:grid; grid-template-columns:1fr 120px; gap:12px; margin-bottom:12px;">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Forward DNS Server (IP)</label>
          <input type="text" name="dns_server" id="edit_do_server" class="form-control" required style="font-family:var(--font-mono);">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Port</label>
          <input type="number" name="port" id="edit_do_port" class="form-control" min="1" max="65535">
        </div>
      </div>
      <div class="form-group" style="margin-bottom:18px;">
        <label class="form-label">Description</label>
        <input type="text" name="description" id="edit_do_description" class="form-control">
      </div>
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeEditDomainOverrideModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Domain Override</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Host Modal -->
<div id="editHostModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:520px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1rem; font-weight:700;">Edit DNS Host Override</h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeEditHostModal()">&times;</button>
    </div>
    <form id="editHostForm" method="POST" action="">
      <?= csrf_field() ?>
      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label">Fully Qualified Domain Name (FQDN)</label>
        <input type="text" name="hostname" id="edit_hostname" class="form-control" required style="font-family:var(--font-mono);">
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label">Mapped IP Address</label>
        <input type="text" name="ip_address" id="edit_ip_address" class="form-control" required style="font-family:var(--font-mono);">
      </div>
      <div class="form-group" style="margin-bottom:18px;">
        <label class="form-label">Description</label>
        <input type="text" name="description" id="edit_description" class="form-control">
      </div>
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeEditHostModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Host</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchDnsTab(tabId, btn) {
  document.querySelectorAll('.dns-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.dns-panel').forEach(p => p.classList.remove('active'));
  if (btn) btn.classList.add('active');
  const panel = document.getElementById('panel-' + tabId);
  if (panel) panel.classList.add('active');

  const url = new URL(window.location);
  url.searchParams.set('tab', tabId);
  window.history.replaceState({}, '', url);
}

document.addEventListener('DOMContentLoaded', () => {
  const params = new URLSearchParams(window.location.search);
  const tab = params.get('tab');
  if (tab) {
    const btn = Array.from(document.querySelectorAll('.dns-tab')).find(b => b.getAttribute('onclick')?.includes("'" + tab + "'"));
    if (btn) switchDnsTab(tab, btn);
  }
});

function showResolverHelp(behavior) {
  document.querySelectorAll('.resolver-help').forEach(p => {
    p.style.display = p.getAttribute('data-behavior') === behavior ? '' : 'none';
  });
}

function openAddFeedModal() { document.getElementById('addFeedModal').style.display = 'flex'; }
function closeAddFeedModal() { document.getElementById('addFeedModal').style.display = 'none'; }

function openAddRuleModal(action) {
  document.getElementById('rule_action').value = action;
  document.getElementById('ruleModalTitle').textContent = action === 'whitelist' ? 'Add Domain Whitelist' : 'Add Domain Blacklist';
  document.getElementById('addRuleModal').style.display = 'flex';
}
function closeAddRuleModal() { document.getElementById('addRuleModal').style.display = 'none'; }

function openAddHostModal() { document.getElementById('addHostModal').style.display = 'flex'; }
function closeAddHostModal() { document.getElementById('addHostModal').style.display = 'none'; }

function openAddDomainOverrideModal() { document.getElementById('addDomainOverrideModal').style.display = 'flex'; }
function closeAddDomainOverrideModal() { document.getElementById('addDomainOverrideModal').style.display = 'none'; }

function openEditDomainOverrideModal(d) {
  document.getElementById('edit_do_domain').value = d.domain;
  document.getElementById('edit_do_server').value = d.dns_server;
  document.getElementById('edit_do_port').value = d.port || 53;
  document.getElementById('edit_do_description').value = d.description || '';
  document.getElementById('editDomainOverrideForm').action = '<?= base_url('dns/domain-overrides') ?>/' + d.id + '/update';
  document.getElementById('editDomainOverrideModal').style.display = 'flex';
}
function closeEditDomainOverrideModal() { document.getElementById('editDomainOverrideModal').style.display = 'none'; }

function openEditHostModal(h) {
  document.getElementById('edit_hostname').value = h.hostname;
  document.getElementById('edit_ip_address').value = h.ip_address;
  document.getElementById('edit_description').value = h.description || '';
  document.getElementById('editHostForm').action = '<?= base_url('dns/hosts') ?>/' + h.id + '/update';
  document.getElementById('editHostModal').style.display = 'flex';
}
function closeEditHostModal() { document.getElementById('editHostModal').style.display = 'none'; }
</script>

<?= $this->endSection() ?>
