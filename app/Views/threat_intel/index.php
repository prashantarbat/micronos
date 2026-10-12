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
      <span class="current">Threat Intelligence Feeds</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-rose">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
      </div>
      Threat Intelligence &amp; IoC Blacklisting
    </h1>
    <p class="page-subtitle">Enforce dynamic threat feeds (AbuseIPDB, OTX, MISP) and manual IoC blacklists with zero-latency kernel packet dropping via nftables and domain sinkholing via DNSBL.</p>
  </div>
  <div class="page-header-actions">
    <button type="button" class="btn btn-primary" onclick="openManualIocModal()" style="display:inline-flex; align-items:center; gap:6px; background:linear-gradient(135deg, #f43f5e 0%, #e11d48 100%); border-color:#f43f5e; box-shadow:0 4px 14px rgba(244,63,94,0.35);">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
      </svg>
      Add Manual IoC / Blacklist
    </button>

    <a href="<?= base_url('security/threat-intel/compile') ?>" target="_blank" class="btn btn-ghost" style="display:inline-flex; align-items:center; gap:6px;" title="Inspect compiled nftables kernel ruleset">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
      </svg>
      nftables Set
    </a>

    <a href="<?= base_url('security/threat-intel/compile-dnsbl') ?>" target="_blank" class="btn btn-ghost" style="display:inline-flex; align-items:center; gap:6px;" title="Inspect compiled Unbound DNSBL domain sinkhole zone">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
      </svg>
      DNSBL Sinkhole
    </a>

    <button type="button" class="btn btn-ghost" onclick="openSettingsModal()" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
      </svg>
      Policies
    </button>

    <form action="<?= base_url('security/threat-intel/sync') ?>" method="POST" style="display:inline;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost" style="display:inline-flex; align-items:center; gap:6px;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Sync Feeds
      </button>
    </form>
  </div>
</div>

<!-- ── KPI Summary Cards ── -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:24px;" class="animate-in animate-in-delay-1">
  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-rose, #f43f5e);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Active Blocklist Indicators</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="font-size:1.4rem; font-weight:700; color:var(--text-primary);"><?= number_format($stats['total_indicators']) ?></span>
      <span class="badge badge-danger">Live Set</span>
    </div>
    <div style="display:flex; gap:8px; font-size:0.75rem; color:var(--text-muted); margin-top:4px;">
      <span>IPs: <strong style="color:var(--text-primary);"><?= number_format($stats['total_ips'] ?? 0) ?></strong></span>
      <span>&bull;</span>
      <span>FQDNs: <strong style="color:var(--text-primary);"><?= number_format($stats['total_fqdns'] ?? 0) ?></strong></span>
    </div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-amber, #f59e0b);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Manual Blacklisted IoCs</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="font-size:1.4rem; font-weight:700; color:var(--text-primary);"><?= number_format($stats['manual_count'] ?? 0) ?></span>
      <span class="badge" style="background:rgba(245,158,11,0.15); color:#fbbf24; border:1px solid rgba(245,158,11,0.3); font-weight:600;">Custom IoCs</span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Admin enforced IPs, CIDRs &amp; FQDNs</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-indigo, #6366f1);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Subscribed Feeds</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="font-size:1.4rem; font-weight:700; color:var(--text-primary);"><?= $stats['active_feeds'] ?></span>
      <span style="font-size:0.8rem; color:var(--text-muted);">/ <?= $stats['total_feeds'] ?> active</span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">AbuseIPDB, OTX, MISP, Spamhaus</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-emerald, #10b981);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">RFC 1918 Private Safeguard</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="width:10px; height:10px; border-radius:50%; background:var(--color-emerald); display:inline-block; box-shadow:0 0 8px var(--color-emerald);"></span>
      <span style="font-size:1.1rem; font-weight:700; color:var(--text-primary);"><?= ! empty($settings['safeguard_rfc1918']) ? 'HARD GUARD ACTIVE' : 'DISABLED' ?></span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">LAN &amp; Bogon protection enforced</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid #06b6d4;">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Mitigation Action</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="font-size:1.1rem; font-weight:700; color:var(--text-primary); text-transform:uppercase;"><?= esc($settings['action']) ?></span>
      <span class="badge badge-subtle"><?= ! empty($settings['log_blocked_packets']) ? 'LOGGING ON' : 'SILENT' ?></span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Kernel Prerouting &amp; DNS Sinkhole</div>
  </div>
</div>

<!-- ── Manual IoC & Blacklisted IPs / Domains Management Card ── -->
<div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:24px;">
  <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
      <div style="display:flex; align-items:center; gap:8px;">
        <h2 style="font-size:1rem; font-weight:600; margin:0; color:var(--text-primary);">Manual IoCs &amp; Blacklisted IPs / FQDNs</h2>
        <span class="badge" style="background:rgba(244,63,94,0.15); color:#fb7185; border:1px solid rgba(244,63,94,0.3); font-weight:600;">
          <?= count($manualIndicators) ?> Custom Entries
        </span>
      </div>
      <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
        Administrator-specified IPs &amp; CIDRs are dropped via <code>nftables</code> set <code>threat_intel_ipv4</code>; FQDN domain names are sinkholed to <code>0.0.0.0</code> via Unbound DNS.
      </div>
    </div>
    <div style="display:flex; align-items:center; gap:10px;">
      <input type="text" id="manualIocSearchInput" class="form-control" placeholder="Search manual IoC..." style="max-width:200px; font-size:0.8rem;" onkeyup="filterManualIndicators()">
      <button type="button" class="btn btn-sm btn-primary" onclick="openManualIocModal()" style="display:inline-flex; align-items:center; gap:6px; background:linear-gradient(135deg, #f43f5e 0%, #e11d48 100%); border-color:#f43f5e;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
        </svg>
        Add Manual IoC
      </button>
    </div>
  </div>

  <div style="overflow-x:auto;">
    <table class="table" style="width:100%; border-collapse:collapse;" id="manualIocsTable">
      <thead>
        <tr style="border-bottom:1px solid var(--border-color); background:rgba(255,255,255,0.02); font-size:0.75rem; color:var(--text-muted); text-transform:uppercase;">
          <th style="padding:12px 16px; text-align:left;">Indicator Value</th>
          <th style="padding:12px 16px; text-align:left;">Target Type</th>
          <th style="padding:12px 16px; text-align:left;">Threat Category</th>
          <th style="padding:12px 16px; text-align:left;">Comment / Reference</th>
          <th style="padding:12px 16px; text-align:center;">Confidence</th>
          <th style="padding:12px 16px; text-align:left;">Expiration</th>
          <th style="padding:12px 16px; text-align:right;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($manualIndicators)): ?>
          <tr>
            <td colspan="7" style="padding:28px; text-align:center; color:var(--text-muted);">
              <div style="display:inline-flex; flex-direction:column; align-items:center; gap:8px;">
                <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--text-muted);">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span>No manual threat indicators or blacklisted domains configured yet.</span>
                <button type="button" class="btn btn-sm btn-ghost" onclick="openManualIocModal()" style="border:1px solid rgba(255,255,255,0.15); margin-top:4px;">
                  + Add First Manual IoC
                </button>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($manualIndicators as $m): ?>
            <?php
              $isFqdn = ($m['indicator_type'] ?? '') === 'fqdn';
              $isCidr = ($m['indicator_type'] ?? '') === 'ipv4_cidr';
            ?>
            <tr style="border-bottom:1px solid rgba(255,255,255,0.04); transition:background 0.2s ease;">
              <td style="padding:12px 16px;">
                <div style="font-family:monospace; font-weight:700; color:var(--text-primary); font-size:0.9rem;">
                  <?= esc($m['indicator_value']) ?>
                </div>
              </td>
              <td style="padding:12px 16px;">
                <?php if ($isFqdn): ?>
                  <span class="badge" style="background:rgba(99,102,241,0.15); color:#818cf8; border:1px solid rgba(99,102,241,0.3); font-weight:600;">
                    DNSBL FQDN
                  </span>
                <?php elseif ($isCidr): ?>
                  <span class="badge" style="background:rgba(245,158,11,0.15); color:#fbbf24; border:1px solid rgba(245,158,11,0.3); font-weight:600;">
                    IPv4 CIDR
                  </span>
                <?php else: ?>
                  <span class="badge" style="background:rgba(244,63,94,0.15); color:#fb7185; border:1px solid rgba(244,63,94,0.3); font-weight:600;">
                    IPv4 Host
                  </span>
                <?php endif; ?>
              </td>
              <td style="padding:12px 16px;">
                <span class="badge badge-subtle" style="font-weight:600; text-transform:uppercase; font-size:0.7rem;">
                  <?= esc($m['threat_type']) ?>
                </span>
              </td>
              <td style="padding:12px 16px; font-size:0.8rem; color:var(--text-muted); max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= esc($m['comment'] ?? '') ?>">
                <?= esc($m['comment'] ?: '—') ?>
              </td>
              <td style="padding:12px 16px; text-align:center;">
                <span class="badge badge-danger"><?= (int) $m['confidence_score'] ?>%</span>
              </td>
              <td style="padding:12px 16px; font-size:0.78rem; color:var(--text-muted);">
                <?php if (! empty($m['expires_at'])): ?>
                  <span title="Expires: <?= esc($m['expires_at']) ?>"><?= esc(substr($m['expires_at'], 0, 10)) ?></span>
                <?php else: ?>
                  <span style="color:var(--color-emerald, #34d399); font-weight:600;">Permanent</span>
                <?php endif; ?>
              </td>
              <td style="padding:12px 16px; text-align:right;">
                <form action="<?= base_url('security/threat-intel/delete-manual-ioc/' . $m['id']) ?>" method="POST" style="display:inline;" onsubmit="return confirm('Remove \'<?= esc($m['indicator_value']) ?>\' from manual blacklist?');">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm btn-danger" title="Delete Indicator">&times;</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Feeds Management Table ── -->
<div class="card card-glass animate-in animate-in-delay-3" style="margin-bottom:24px;">
  <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
    <div>
      <h2 style="font-size:1rem; font-weight:600; margin:0; color:var(--text-primary);">Configured Threat Feeds</h2>
      <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Automated external reputation feeds synchronized via cron or on-demand.</div>
    </div>
    <button type="button" class="btn btn-sm btn-primary" onclick="openFeedModal()" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
      </svg>
      Add Feed
    </button>
  </div>

  <div style="overflow-x:auto;">
    <table class="table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr style="border-bottom:1px solid var(--border-color); background:rgba(255,255,255,0.02); font-size:0.75rem; color:var(--text-muted); text-transform:uppercase;">
          <th style="padding:12px 16px; text-align:left;">Feed Source</th>
          <th style="padding:12px 16px; text-align:left;">Type</th>
          <th style="padding:12px 16px; text-align:left;">Update Cadence</th>
          <th style="padding:12px 16px; text-align:left;">Last Synchronization</th>
          <th style="padding:12px 16px; text-align:center;">Indicators</th>
          <th style="padding:12px 16px; text-align:center;">State</th>
          <th style="padding:12px 16px; text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($feeds as $f): ?>
          <?php if (($f['feed_type'] ?? '') === 'manual') continue; ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,0.05); transition:background 0.2s ease;">
            <td style="padding:14px 16px;">
              <div style="font-weight:600; color:var(--text-primary);"><?= esc($f['name']) ?></div>
              <div style="font-size:0.75rem; color:var(--text-muted); font-family:monospace;"><?= esc(substr($f['url'] ?? '', 0, 48)) ?><?= strlen($f['url'] ?? '') > 48 ? '...' : '' ?></div>
            </td>
            <td style="padding:14px 16px;">
              <span class="badge" style="background:rgba(244,63,94,0.15); color:#fb7185; border:1px solid rgba(244,63,94,0.3); font-weight:600;">
                <?= strtoupper(esc($f['feed_type'])) ?>
              </span>
            </td>
            <td style="padding:14px 16px; font-size:0.8rem; color:var(--text-muted);">
              Every <?= (int) $f['update_interval_hours'] ?> hours (Min score: <?= (int) $f['min_confidence'] ?>%)
            </td>
            <td style="padding:14px 16px; font-size:0.8rem;">
              <?php if ($f['last_status'] === 'success'): ?>
                <div style="color:var(--color-emerald, #34d399); font-weight:600;">Success</div>
                <div style="color:var(--text-muted); font-size:0.75rem;"><?= esc($f['last_sync_at']) ?></div>
              <?php elseif ($f['last_status'] === 'failed'): ?>
                <div style="color:var(--color-rose, #f43f5e); font-weight:600;">Failed</div>
                <div style="color:var(--text-muted); font-size:0.75rem;" title="<?= esc($f['last_error'] ?? '') ?>"><?= esc(substr($f['last_error'] ?? '', 0, 30)) ?>...</div>
              <?php else: ?>
                <div style="color:var(--text-muted);">Pending Sync</div>
              <?php endif; ?>
            </td>
            <td style="padding:14px 16px; text-align:center;">
              <span class="badge badge-subtle" style="font-weight:700;"><?= number_format($f['indicator_count']) ?></span>
            </td>
            <td style="padding:14px 16px; text-align:center;">
              <?php if (! empty($f['enabled'])): ?>
                <span class="badge badge-success">Enabled</span>
              <?php else: ?>
                <span class="badge badge-neutral">Disabled</span>
              <?php endif; ?>
            </td>
            <td style="padding:14px 16px; text-align:right;">
              <div style="display:flex; gap:6px; justify-content:flex-end;">
                <form action="<?= base_url('security/threat-intel/sync/' . $f['id']) ?>" method="POST" style="display:inline;">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm btn-ghost" title="Sync Feed Now">Sync</button>
                </form>
                <button type="button" class="btn btn-sm btn-ghost" onclick='editFeed(<?= json_encode($f) ?>)' title="Edit Settings">Edit</button>
                <form action="<?= base_url('security/threat-intel/toggle/' . $f['id']) ?>" method="POST" style="display:inline;">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm btn-ghost"><?= ! empty($f['enabled']) ? 'Disable' : 'Enable' ?></button>
                </form>
                <form action="<?= base_url('security/threat-intel/delete/' . $f['id']) ?>" method="POST" style="display:inline;" onsubmit="return confirm('Delete this threat feed?');">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm btn-danger">&times;</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Two Column: Active Indicators Explorer & Whitelist ── -->
<div style="display:grid; grid-template-columns:2fr 1fr; gap:20px;" class="animate-in animate-in-delay-3">
  <!-- Active Indicators Table -->
  <div class="card card-glass" style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <div>
        <h3 style="font-size:0.95rem; font-weight:600; margin:0; color:var(--text-primary);">Active Blocked IoCs (Preview)</h3>
        <div style="font-size:0.75rem; color:var(--text-muted);">Current threat indicators compiled into the live kernel firewall set and DNSBL sinkhole.</div>
      </div>
      <input type="text" id="tiSearchInput" class="form-control" placeholder="Search IP or Threat Type..." style="max-width:220px; font-size:0.8rem;" onkeyup="filterIndicators()">
    </div>

    <div style="overflow-x:auto; max-height:420px; overflow-y:auto;">
      <table class="table" style="width:100%; border-collapse:collapse;" id="indicatorsTable">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color); font-size:0.75rem; color:var(--text-muted); text-transform:uppercase;">
            <th style="padding:8px 12px; text-align:left;">Indicator Value</th>
            <th style="padding:8px 12px; text-align:left;">Type</th>
            <th style="padding:8px 12px; text-align:left;">Threat Classification</th>
            <th style="padding:8px 12px; text-align:center;">Score</th>
            <th style="padding:8px 12px; text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($indicators)): ?>
            <tr>
              <td colspan="5" style="padding:24px; text-align:center; color:var(--text-muted);">No threat indicators found. Click "Sync All Feeds" or add manual IoCs to populate.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($indicators as $ind): ?>
              <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
                <td style="padding:10px 12px; font-family:monospace; font-weight:600; color:var(--text-primary);">
                  <?= esc($ind['indicator_value']) ?>
                </td>
                <td style="padding:10px 12px;">
                  <?php if (($ind['indicator_type'] ?? '') === 'fqdn'): ?>
                    <span class="badge" style="background:rgba(99,102,241,0.15); color:#818cf8; font-size:0.7rem;">FQDN</span>
                  <?php elseif (($ind['indicator_type'] ?? '') === 'ipv4_cidr'): ?>
                    <span class="badge" style="background:rgba(245,158,11,0.15); color:#fbbf24; font-size:0.7rem;">CIDR</span>
                  <?php else: ?>
                    <span class="badge" style="background:rgba(244,63,94,0.15); color:#fb7185; font-size:0.7rem;">IPv4</span>
                  <?php endif; ?>
                </td>
                <td style="padding:10px 12px;">
                  <span class="badge badge-subtle"><?= esc($ind['threat_type']) ?></span>
                </td>
                <td style="padding:10px 12px; text-align:center;">
                  <span class="badge badge-danger"><?= (int) $ind['confidence_score'] ?>%</span>
                </td>
                <td style="padding:10px 12px; text-align:right;">
                  <form action="<?= base_url('security/threat-intel/add-whitelist') ?>" method="POST" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="indicator_value" value="<?= esc($ind['indicator_value']) ?>">
                    <input type="hidden" name="reason" value="Whitelisted from active IoC explorer">
                    <button type="submit" class="btn btn-sm btn-ghost" title="Whitelist this indicator">Whitelist</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- False-Positive Whitelist Management -->
  <div class="card card-glass" style="padding:20px;">
    <h3 style="font-size:0.95rem; font-weight:600; margin:0 0 4px; color:var(--text-primary);">IP / FQDN Whitelist</h3>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:14px;">Bypass specific IPs or domain names from ever being blocked.</div>

    <form action="<?= base_url('security/threat-intel/add-whitelist') ?>" method="POST" style="margin-bottom:18px;">
      <?= csrf_field() ?>
      <div style="margin-bottom:8px;">
        <input type="text" name="indicator_value" class="form-control" placeholder="e.g. 8.8.8.8 or legitimate.company.com" required style="font-size:0.85rem;">
      </div>
      <div style="margin-bottom:10px;">
        <input type="text" name="reason" class="form-control" placeholder="Reason (e.g. DNS Resolver / Partner Domain)" style="font-size:0.8rem;">
      </div>
      <button type="submit" class="btn btn-sm btn-ghost btn-full" style="border:1px solid rgba(255,255,255,0.15);">
        + Add to Whitelist
      </button>
    </form>

    <div style="max-height:260px; overflow-y:auto;">
      <?php if (empty($whitelists)): ?>
        <div style="font-size:0.8rem; color:var(--text-muted); text-align:center; padding:16px;">No whitelisted entries.</div>
      <?php else: ?>
        <?php foreach ($whitelists as $wl): ?>
          <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 10px; background:rgba(255,255,255,0.02); border-radius:6px; margin-bottom:6px;">
            <div>
              <div style="font-family:monospace; font-weight:600; font-size:0.85rem; color:var(--text-primary);"><?= esc($wl['indicator_value']) ?></div>
              <div style="font-size:0.7rem; color:var(--text-muted);"><?= esc($wl['reason'] ?? 'Manual Whitelist') ?></div>
            </div>
            <form action="<?= base_url('security/threat-intel/delete-whitelist/' . $wl['id']) ?>" method="POST" style="display:inline;">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-sm btn-ghost" style="color:var(--color-rose, #f43f5e);">&times;</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Modal: Add Manual IoC / Blacklist ── -->
<div id="manualIocModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(6px); z-index:999; align-items:center; justify-content:center; padding:16px;">
  <div class="card card-glass" style="max-width:620px; width:100%; max-height:92vh; overflow-y:auto; padding:24px; border:1px solid var(--border-color); box-shadow:0 12px 48px rgba(0,0,0,0.6);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <div style="display:flex; align-items:center; gap:10px;">
        <div style="width:36px; height:36px; border-radius:8px; background:rgba(244,63,94,0.15); display:flex; align-items:center; justify-content:center; color:#fb7185;">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
        </div>
        <div>
          <h2 style="font-size:1.15rem; font-weight:700; margin:0; color:var(--text-primary);">Add Manual IoC / Blacklist Entry</h2>
          <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Enforce zero-trust blocking on malicious IPv4, CIDR blocks, or domain names (FQDNs).</div>
        </div>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeManualIocModal()" style="font-size:1.2rem; line-height:1;">&times;</button>
    </div>

    <form action="<?= base_url('security/threat-intel/add-manual-ioc') ?>" method="POST">
      <?= csrf_field() ?>

      <div style="margin-bottom:14px;">
        <label class="form-label" for="manual_indicator_value" style="font-weight:600; display:flex; justify-content:space-between;">
          <span>Indicator(s) to Blacklist *</span>
          <span style="font-size:0.75rem; color:var(--text-muted); font-weight:400;">Single or bulk paste (one per line)</span>
        </label>
        <textarea name="indicator_value" id="manual_indicator_value" class="form-control" rows="5" placeholder="198.51.100.25&#10;203.0.113.0/24&#10;malicious-c2.evil-corp.com&#10;phishing-portal.bad-actors.org" required style="font-family:monospace; font-size:0.85rem; line-height:1.45;"></textarea>
        <div style="font-size:0.73rem; color:var(--text-muted); margin-top:5px; line-height:1.4;">
          <strong style="color:var(--text-primary);">Automatic Target Routing:</strong> IPv4 &amp; CIDRs are compiled to <code>nftables</code> kernel set. FQDN domains are sinkholed to <code>0.0.0.0</code> in Unbound DNS. RFC 1918 private subnets are automatically safeguarded.
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
        <div>
          <label class="form-label" for="manual_threat_type" style="font-weight:600;">Threat Classification *</label>
          <select name="threat_type" id="manual_threat_type" class="form-control">
            <option value="custom_blacklist">Custom Blacklist (General)</option>
            <option value="c2">Command &amp; Control (C2)</option>
            <option value="ransomware">Ransomware Infrastructure</option>
            <option value="phishing">Phishing &amp; Credential Theft</option>
            <option value="scanner">Scanner / Exploit Prober</option>
            <option value="botnet">Botnet Node / DDoS Host</option>
            <option value="malware">Malware Distribution Point</option>
            <option value="spam">Spam / Fraud Origin</option>
          </select>
        </div>
        <div>
          <label class="form-label" for="manual_confidence_score" style="font-weight:600;">Confidence Score (%)</label>
          <input type="number" name="confidence_score" id="manual_confidence_score" class="form-control" value="100" min="1" max="100">
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
        <div>
          <label class="form-label" for="manual_expires_days" style="font-weight:600;">Expiration / TTL</label>
          <select name="expires_days" id="manual_expires_days" class="form-control">
            <option value="">Permanent (Never expires)</option>
            <option value="7">7 Days</option>
            <option value="14">14 Days</option>
            <option value="30">30 Days</option>
            <option value="90">90 Days</option>
            <option value="180">180 Days</option>
            <option value="365">1 Year</option>
          </select>
        </div>
        <div>
          <label class="form-label" for="manual_comment" style="font-weight:600;">Comment / Ticket Reference</label>
          <input type="text" name="comment" id="manual_comment" class="form-control" placeholder="e.g. INC-9401 / Urgent SOC containment">
        </div>
      </div>

      <div style="background:rgba(244,63,94,0.08); border:1px solid rgba(244,63,94,0.2); border-radius:8px; padding:10px 14px; margin-bottom:18px; display:flex; align-items:center; gap:10px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:#fb7185; flex-shrink:0;">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        <div style="font-size:0.75rem; color:var(--text-muted); line-height:1.4;">
          Entries take effect immediately upon submission. Live firewall rules and DNSBL zone configs are updated synchronously.
        </div>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeManualIocModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg, #f43f5e 0%, #e11d48 100%); border-color:#f43f5e;">
          Enforce Blacklist Entry
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Feed Create / Edit ── -->
<div id="feedModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(6px); z-index:999; align-items:center; justify-content:center; padding:16px;">
  <div class="card card-glass" style="max-width:600px; width:100%; max-height:90vh; overflow-y:auto; padding:24px; border:1px solid var(--border-color); box-shadow:0 10px 40px rgba(0,0,0,0.5);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h2 id="feedModalTitle" style="font-size:1.15rem; font-weight:700; margin:0; color:var(--text-primary);">Configure Threat Intelligence Feed</h2>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeFeedModal()">&times;</button>
    </div>

    <form id="feedForm" action="<?= base_url('security/threat-intel/save-feed') ?>" method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="feed_id" value="">

      <div style="margin-bottom:14px;">
        <label class="form-label" for="feed_name">Feed Name *</label>
        <input type="text" name="name" id="feed_name" class="form-control" placeholder="e.g. AbuseIPDB Global Blacklist" required>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
        <div>
          <label class="form-label" for="feed_slug">Identifier Slug</label>
          <input type="text" name="slug" id="feed_slug" class="form-control" placeholder="e.g. abuseipdb-blacklist">
        </div>
        <div>
          <label class="form-label" for="feed_type">Feed Format / Protocol *</label>
          <select name="feed_type" id="feed_type" class="form-control">
            <option value="abuseipdb">AbuseIPDB (REST API JSON)</option>
            <option value="alienvault_otx">AlienVault OTX (Pulses API)</option>
            <option value="misp">MISP Threat Sharing Platform</option>
            <option value="spamhaus_drop">Spamhaus DROP / EDROP</option>
            <option value="emerging_threats">Emerging Threats (Plain List)</option>
            <option value="custom_url">Generic HTTP/HTTPS IP List</option>
          </select>
        </div>
      </div>

      <div style="margin-bottom:14px;">
        <label class="form-label" for="feed_url">Feed URL *</label>
        <input type="url" name="url" id="feed_url" class="form-control" placeholder="https://api.abuseipdb.com/api/v2/blacklist" required>
      </div>

      <div style="margin-bottom:14px;">
        <label class="form-label" for="feed_api_key">API Key / Auth Token (Optional)</label>
        <input type="password" name="api_key" id="feed_api_key" class="form-control" placeholder="Leave blank if public / unauthenticated">
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:18px;">
        <div>
          <label class="form-label" for="feed_min_confidence">Minimum Confidence Score (%)</label>
          <input type="number" name="min_confidence" id="feed_min_confidence" class="form-control" value="80" min="1" max="100">
        </div>
        <div>
          <label class="form-label" for="feed_update_interval_hours">Sync Cadence (Hours)</label>
          <input type="number" name="update_interval_hours" id="feed_update_interval_hours" class="form-control" value="6" min="1" max="168">
        </div>
      </div>

      <div style="display:flex; justify-content:space-between; align-items:center;">
        <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; cursor:pointer;">
          <input type="checkbox" name="enabled" id="feed_enabled" value="1" checked>
          <span style="font-weight:600;">Enable Automatic Sync</span>
        </label>
        <div style="display:flex; gap:10px;">
          <button type="button" class="btn btn-ghost" onclick="closeFeedModal()">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Feed</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Global Policy Settings ── -->
<div id="settingsModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(6px); z-index:999; align-items:center; justify-content:center; padding:16px;">
  <div class="card card-glass" style="max-width:540px; width:100%; padding:24px; border:1px solid var(--border-color); box-shadow:0 10px 40px rgba(0,0,0,0.5);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h2 style="font-size:1.15rem; font-weight:700; margin:0; color:var(--text-primary);">Threat Intelligence Policies</h2>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeSettingsModal()">&times;</button>
    </div>

    <form action="<?= base_url('security/threat-intel/save-settings') ?>" method="POST">
      <?= csrf_field() ?>

      <div style="margin-bottom:14px;">
        <label class="form-label" for="ti_action">Firewall Action for Threat IoCs</label>
        <select name="action" id="ti_action" class="form-control">
          <option value="drop" <?= ($settings['action'] ?? '') === 'drop' ? 'selected' : '' ?>>Drop (Silently discard packet)</option>
          <option value="reject" <?= ($settings['action'] ?? '') === 'reject' ? 'selected' : '' ?>>Reject (Send ICMP unreachable / TCP RST)</option>
        </select>
      </div>

      <div style="margin-bottom:14px;">
        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
          <input type="checkbox" name="safeguard_rfc1918" value="1" <?= ! empty($settings['safeguard_rfc1918']) ? 'checked' : '' ?> style="margin-top:3px;">
          <div>
            <div style="font-weight:600; color:var(--text-primary);">Enforce RFC 1918 &amp; Bogon Safeguard</div>
            <div style="font-size:0.75rem; color:var(--text-muted);">Strictly prevents private LAN subnets (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, CGNAT 100.64.0.0/10) from ever being blocked.</div>
          </div>
        </label>
      </div>

      <div style="margin-bottom:14px;">
        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
          <input type="checkbox" name="log_blocked_packets" value="1" <?= ! empty($settings['log_blocked_packets']) ? 'checked' : '' ?> style="margin-top:3px;">
          <div>
            <div style="font-weight:600; color:var(--text-primary);">Log Dropped Packets</div>
            <div style="font-size:0.75rem; color:var(--text-muted);">Emit kernel syslog alerts with prefix <code>[TI-BLOCK-SRC]</code> for SIEM ingestion.</div>
          </div>
        </label>
      </div>

      <div style="margin-bottom:20px;">
        <label class="form-label" for="ti_auto_prune_days">Auto-Prune Stale Indicators After</label>
        <div style="display:flex; align-items:center; gap:8px;">
          <input type="number" name="auto_prune_days" id="ti_auto_prune_days" class="form-control" value="<?= (int) ($settings['auto_prune_days'] ?? 7) ?>" min="1" max="90" style="max-width:120px;">
          <span style="font-size:0.85rem; color:var(--text-muted);">days</span>
        </div>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeSettingsModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Settings</button>
      </div>
    </form>
  </div>
</div>

<script>
function openManualIocModal() {
  document.getElementById('manual_indicator_value').value = '';
  document.getElementById('manual_threat_type').value = 'custom_blacklist';
  document.getElementById('manual_confidence_score').value = '100';
  document.getElementById('manual_expires_days').value = '';
  document.getElementById('manual_comment').value = '';
  document.getElementById('manualIocModal').style.display = 'flex';
  setTimeout(() => document.getElementById('manual_indicator_value').focus(), 50);
}

function closeManualIocModal() {
  document.getElementById('manualIocModal').style.display = 'none';
}

function filterManualIndicators() {
  const query = document.getElementById('manualIocSearchInput').value.toLowerCase();
  const rows = document.querySelectorAll('#manualIocsTable tbody tr');
  rows.forEach(r => {
    const text = r.innerText.toLowerCase();
    r.style.display = text.includes(query) ? '' : 'none';
  });
}

function openFeedModal() {
  document.getElementById('feed_id').value = '';
  document.getElementById('feedModalTitle').innerText = 'Add Threat Intelligence Feed';
  document.getElementById('feed_name').value = '';
  document.getElementById('feed_slug').value = '';
  document.getElementById('feed_type').value = 'abuseipdb';
  document.getElementById('feed_url').value = '';
  document.getElementById('feed_api_key').value = '';
  document.getElementById('feed_min_confidence').value = '80';
  document.getElementById('feed_update_interval_hours').value = '6';
  document.getElementById('feed_enabled').checked = true;
  document.getElementById('feedModal').style.display = 'flex';
}

function editFeed(f) {
  document.getElementById('feed_id').value = f.id || '';
  document.getElementById('feedModalTitle').innerText = 'Edit ' + f.name;
  document.getElementById('feed_name').value = f.name || '';
  document.getElementById('feed_slug').value = f.slug || '';
  document.getElementById('feed_type').value = f.feed_type || 'custom_url';
  document.getElementById('feed_url').value = f.url || '';
  document.getElementById('feed_api_key').value = f.api_key || '';
  document.getElementById('feed_min_confidence').value = f.min_confidence || '80';
  document.getElementById('feed_update_interval_hours').value = f.update_interval_hours || '6';
  document.getElementById('feed_enabled').checked = (parseInt(f.enabled) === 1);
  document.getElementById('feedModal').style.display = 'flex';
}

function closeFeedModal() {
  document.getElementById('feedModal').style.display = 'none';
}

function openSettingsModal() {
  document.getElementById('settingsModal').style.display = 'flex';
}

function closeSettingsModal() {
  document.getElementById('settingsModal').style.display = 'none';
}

function filterIndicators() {
  const query = document.getElementById('tiSearchInput').value.toLowerCase();
  const rows = document.querySelectorAll('#indicatorsTable tbody tr');
  rows.forEach(r => {
    const text = r.innerText.toLowerCase();
    r.style.display = text.includes(query) ? '' : 'none';
  });
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeFeedModal();
    closeSettingsModal();
    closeManualIocModal();
  }
});
</script>

<?= $this->endSection() ?>
