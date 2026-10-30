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
      <span class="current">CVEs &amp; Security Advisories</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-rose" style="background:rgba(244,63,94,0.12); color:#f43f5e;">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
        </svg>
      </div>
      CVEs &amp; Security Advisories
    </h1>
    <p class="page-subtitle">Authenticated package vulnerability auditor for MicronOS &amp; Debian Linux. Direct
      cross-referencing with the NIST National Vulnerability Database (NVD) &amp; Debian Security Bug Tracker.</p>
  </div>
  <div class="page-header-actions" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
    <!-- Run Authenticated Scan -->
    <form action="<?= base_url('security/cve/scan') ?>" method="POST" style="margin:0;"
      onsubmit="showScanLoading(this)">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary" id="btnRunScan"
        style="display:inline-flex; align-items:center; gap:6px; background:linear-gradient(135deg, #e11d48 0%, #be123c 100%); border-color:#be123c; box-shadow:0 4px 14px rgba(225,29,72,0.35);">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <span id="btnRunScanText">Run Authenticated Scan</span>
      </button>
    </form>

    <!-- Refresh NVD Feeds -->
    <form action="<?= base_url('security/cve/refresh-feed') ?>" method="POST" style="margin:0;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost" style="display:inline-flex; align-items:center; gap:6px;"
        title="Synchronize latest CVE definitions from NIST NVD and Debian Security Tracker">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        Sync NVD Feeds
      </button>
    </form>

    <!-- Export CSV -->
    <a href="<?= base_url('security/cve/export-csv') ?>" class="btn btn-ghost"
      style="display:inline-flex; align-items:center; gap:6px;" title="Export audit findings as compliance CSV report">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round"
          d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
      </svg>
      Export CSV
    </a>

    <!-- System Diagnostics Modal Button -->
    <button type="button" class="btn btn-ghost" onclick="openSysModal()"
      style="display:inline-flex; align-items:center; gap:6px;" title="View system scanner context and Debian version">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round"
          d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      Scanner Info
    </button>
  </div>
</div>

<!-- ── KPI Summary Metric Cards ── -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:20px;"
  class="animate-in animate-in-delay-1">
  <!-- Total Packages Audited -->
  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-cyan, #06b6d4);">
    <div
      style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">
      Audited Packages</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span
        style="font-size:1.4rem; font-weight:700; color:var(--text-primary);"><?= number_format($stats['total_packages']) ?></span>
      <span class="badge"
        style="background:rgba(6,182,212,0.12); color:#06b6d4; border:1px solid rgba(6,182,212,0.25); font-size:0.7rem; font-weight:600;">Authenticated</span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">
      <?= esc($systemInfo['os']) ?>
    </div>
  </div>

  <!-- Critical Vulnerabilities -->
  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-rose, #f43f5e);">
    <div
      style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">
      Critical CVEs (CVSS 9.0+)</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="font-size:1.4rem; font-weight:700; color:#f43f5e;"><?= number_format($stats['critical']) ?></span>
      <?php if ($stats['critical'] > 0): ?>
        <span class="badge badge-danger" style="animation:pulse 2s infinite;">Action Required</span>
      <?php else: ?>
        <span class="badge badge-success">Zero Found</span>
      <?php endif; ?>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Immediate patch recommended</div>
  </div>

  <!-- High Vulnerabilities -->
  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-amber, #f59e0b);">
    <div
      style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">
      High CVEs (CVSS 7.0 - 8.9)</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="font-size:1.4rem; font-weight:700; color:#f59e0b;"><?= number_format($stats['high']) ?></span>
      <span class="badge"
        style="background:rgba(245,158,11,0.12); color:#f59e0b; border:1px solid rgba(245,158,11,0.3); font-size:0.7rem; font-weight:600;">High
        Priority</span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Remediate via apt upgrade</div>
  </div>

  <!-- Medium & Low Vulnerabilities -->
  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-indigo, #6366f1);">
    <div
      style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">
      Medium &amp; Low CVEs</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span
        style="font-size:1.4rem; font-weight:700; color:var(--text-primary);"><?= number_format($stats['medium'] + $stats['low']) ?></span>
      <span style="font-size:0.75rem; color:var(--text-muted);">(<?= $stats['medium'] ?> Med / <?= $stats['low'] ?>
        Low)</span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Routine maintenance window</div>
  </div>

  <!-- Patched / Verified Compliant -->
  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-emerald, #10b981);">
    <div
      style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">
      Patched / Protected</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="font-size:1.4rem; font-weight:700; color:#10b981;"><?= number_format($stats['patched']) ?></span>
      <span class="badge badge-success">Verified Safe</span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">
      Last Scan:
      <?= !empty($stats['last_scan']['created_at']) ? esc(date('M j, H:i', strtotime($stats['last_scan']['created_at']))) : 'Just now' ?>
    </div>
  </div>
</div>

<!-- ── Search & Filter Controls ── -->
<div class="card card-glass" style="padding:14px 18px; margin-bottom:18px;">
  <form method="GET" action="<?= base_url('security/cve') ?>"
    style="display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin:0;">
    <!-- Keyword Search -->
    <div style="flex:1; min-width:240px; position:relative;">
      <input type="text" name="q" value="<?= esc($filters['q']) ?>"
        placeholder="Search by CVE ID (e.g. CVE-2024-6387), package, or vulnerability title..."
        style="width:100%; padding:8px 12px 8px 34px; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); color:var(--text-primary); font-size:0.875rem;">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
        style="position:absolute; left:11px; top:11px; color:var(--text-muted);">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
      </svg>
    </div>

    <!-- Severity Filter -->
    <div style="min-width:140px;">
      <select name="severity" onchange="this.form.submit()"
        style="width:100%; padding:8px 10px; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); color:var(--text-primary); font-size:0.875rem;">
        <option value="">All Severities</option>
        <option value="CRITICAL" <?= $filters['severity'] === 'CRITICAL' ? 'selected' : '' ?>>Critical (9.0 - 10.0)
        </option>
        <option value="HIGH" <?= $filters['severity'] === 'HIGH' ? 'selected' : '' ?>>High (7.0 - 8.9)</option>
        <option value="MEDIUM" <?= $filters['severity'] === 'MEDIUM' ? 'selected' : '' ?>>Medium (4.0 - 6.9)</option>
        <option value="LOW" <?= $filters['severity'] === 'LOW' ? 'selected' : '' ?>>Low (0.1 - 3.9)</option>
      </select>
    </div>

    <!-- Status Filter -->
    <div style="min-width:140px;">
      <select name="status" onchange="this.form.submit()"
        style="width:100%; padding:8px 10px; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); color:var(--text-primary); font-size:0.875rem;">
        <option value="">All Statuses</option>
        <option value="vulnerable" <?= $filters['status'] === 'vulnerable' ? 'selected' : '' ?>>Vulnerable (Action Needed)
        </option>
        <option value="patched" <?= $filters['status'] === 'patched' ? 'selected' : '' ?>>Patched / Not Affected</option>
      </select>
    </div>

    <!-- Buttons -->
    <div style="display:flex; gap:6px;">
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <?php if (!empty($filters['q']) || !empty($filters['severity']) || !empty($filters['status'])): ?>
        <a href="<?= base_url('security/cve') ?>" class="btn btn-ghost btn-sm">Reset</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- ── Advisories Table ── -->
<div class="card card-glass" style="padding:0; overflow:hidden; border:1px solid var(--color-border);">
  <div
    style="padding:14px 18px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center; background:var(--color-surface-2);">
    <div>
      <h3 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0;">
        Vulnerability Findings &amp; NIST NVD Advisories (<?= count($advisories) ?>)
      </h3>
      <p style="font-size:0.75rem; color:var(--text-muted); margin:2px 0 0 0;">
        Results sorted by CVSS Risk Score. Click "NIST NVD" to inspect the official CVE documentation.
      </p>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted);">
      Kernel: <code style="color:var(--text-primary);"><?= esc($systemInfo['kernel']) ?></code>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table" style="width:100%; border-collapse:collapse; text-align:left; font-size:0.85rem;">
      <thead>
        <tr
          style="border-bottom:1px solid var(--color-border); background:var(--color-surface-1); color:var(--text-muted); font-size:0.72rem; text-transform:uppercase; letter-spacing:0.04em;">
          <th style="padding:10px 14px;">CVE &amp; Severity</th>
          <th style="padding:10px 14px;">Package &amp; Versions</th>
          <th style="padding:10px 14px;">Vulnerability Summary</th>
          <th style="padding:10px 14px; text-align:center;">Status</th>
          <th style="padding:10px 14px; text-align:right;">NIST NVD &amp; Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($advisories)): ?>
          <tr>
            <td colspan="5" style="text-align:center; padding:36px; color:var(--text-muted);">
              <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"
                style="margin:0 auto 8px; display:block; opacity:0.5;">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
              </svg>
              No security advisories matching your current filter. Your system packages appear protected.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($advisories as $adv): ?>
            <?php
            $sev = strtoupper($adv['severity']);
            $sevColor = match ($sev) {
              'CRITICAL' => '#f43f5e',
              'HIGH' => '#f59e0b',
              'MEDIUM' => '#6366f1',
              default => '#06b6d4',
            };
            $sevBg = match ($sev) {
              'CRITICAL' => 'rgba(244,63,94,0.12)',
              'HIGH' => 'rgba(245,158,11,0.12)',
              'MEDIUM' => 'rgba(99,102,241,0.12)',
              default => 'rgba(6,182,212,0.12)',
            };
            $isVuln = ($adv['status'] === 'vulnerable');
            ?>
            <tr style="border-bottom:1px solid var(--color-border); transition:background 0.15s ease;"
              onmouseover="this.style.background='var(--color-surface-2)'" onmouseout="this.style.background='transparent'">
              <!-- CVE & Severity -->
              <td style="padding:12px 14px; vertical-align:top; white-space:nowrap;">
                <div style="font-weight:700; font-family:monospace; font-size:0.875rem; color:var(--text-primary);">
                  <?= esc($adv['id']) ?>
                </div>
                <div style="display:flex; align-items:center; gap:6px; margin-top:4px;">
                  <span
                    style="background:<?= $sevBg ?>; color:<?= $sevColor ?>; border:1px solid <?= $sevColor ?>40; font-size:0.68rem; font-weight:700; padding:2px 6px; border-radius:4px; text-transform:uppercase;">
                    <?= esc($adv['severity']) ?>
                  </span>
                  <span style="font-weight:700; font-size:0.75rem; color:<?= $sevColor ?>;">
                    CVSS <?= number_format($adv['cvss_score'], 1) ?>
                  </span>
                </div>
                <div style="font-size:0.68rem; color:var(--text-muted); margin-top:2px;">
                  Vector: <strong><?= esc($adv['attack_vector'] ?? 'NETWORK') ?></strong>
                </div>
              </td>

              <!-- Package & Version -->
              <td style="padding:12px 14px; vertical-align:top; white-space:nowrap;">
                <div style="font-weight:700; color:var(--text-primary); font-family:monospace; font-size:0.85rem;">
                  <?= esc($adv['package_name']) ?>
                </div>
                <div style="font-size:0.72rem; color:var(--text-muted); margin-top:3px;">
                  Installed: <code
                    style="color:<?= $isVuln ? '#f43f5e' : '#10b981' ?>; background:var(--color-surface-3); padding:1px 4px; border-radius:3px;"><?= esc($adv['installed_version']) ?></code>
                </div>
                <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">
                  Fixed in: <code
                    style="color:#10b981; background:var(--color-surface-3); padding:1px 4px; border-radius:3px;"><?= esc($adv['fixed_version'] ?? 'Upstream patch') ?></code>
                </div>
              </td>

              <!-- Title & Summary -->
              <td style="padding:12px 14px; vertical-align:top; max-width:440px;">
                <div
                  style="font-weight:600; color:var(--text-primary); font-size:0.85rem; line-height:1.3; margin-bottom:4px;">
                  <?= esc($adv['title']) ?>
                </div>
                <div style="font-size:0.75rem; color:var(--text-muted); line-height:1.4;">
                  <?= esc(substr($adv['summary'] ?? '', 0, 180)) ?>    <?= strlen($adv['summary'] ?? '') > 180 ? '...' : '' ?>
                </div>
                <?php if (!empty($adv['remediation'])): ?>
                  <div style="margin-top:6px; display:flex; align-items:center; gap:6px;">
                    <span
                      style="font-size:0.68rem; text-transform:uppercase; font-weight:700; color:var(--text-muted);">Fix:</span>
                    <code
                      style="font-size:0.72rem; color:var(--color-cyan, #06b6d4); background:var(--color-surface-3); padding:2px 6px; border-radius:3px; max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= esc($adv['remediation']) ?></code>
                  </div>
                <?php endif; ?>
              </td>

              <!-- Status -->
              <td style="padding:12px 14px; vertical-align:top; text-align:center; white-space:nowrap;">
                <?php if ($isVuln): ?>
                  <span class="badge"
                    style="background:rgba(244,63,94,0.15); color:#f43f5e; border:1px solid rgba(244,63,94,0.3); font-weight:700; font-size:0.72rem; padding:4px 8px;">
                    <svg width="10" height="10" fill="currentColor" viewBox="0 0 8 8"
                      style="margin-right:4px; vertical-align:baseline;">
                      <circle cx="4" cy="4" r="3" />
                    </svg>
                    VULNERABLE
                  </span>
                <?php else: ?>
                  <span class="badge"
                    style="background:rgba(16,185,129,0.15); color:#10b981; border:1px solid rgba(16,185,129,0.3); font-weight:700; font-size:0.72rem; padding:4px 8px;">
                    <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"
                      style="margin-right:4px; vertical-align:middle; display:inline-block;">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    PATCHED
                  </span>
                <?php endif; ?>
              </td>

              <!-- NIST NVD Button & References -->
              <td style="padding:12px 14px; vertical-align:top; text-align:right; white-space:nowrap;">
                <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-end;">
                  <!-- Direct NIST NVD Document Button -->
                  <a href="<?= esc($adv['nvd_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm"
                    style="display:inline-flex; align-items:center; gap:5px; background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color:#ffffff; border:1px solid #0284c7; font-weight:600; font-size:0.75rem; padding:5px 10px; border-radius:4px; box-shadow:0 2px 6px rgba(2,132,199,0.25);"
                    title="Inspect official NIST National Vulnerability Database advisory">
                    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round"
                        d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                    NIST NVD
                  </a>

                  <?php if (!empty($adv['debian_url'])): ?>
                    <a href="<?= esc($adv['debian_url']) ?>" target="_blank" rel="noopener noreferrer"
                      class="btn btn-ghost btn-sm"
                      style="display:inline-flex; align-items:center; gap:4px; font-size:0.7rem; padding:2px 6px; color:var(--text-muted);"
                      title="View Debian Security Bug Tracker advisory">
                      <span>Debian Tracker</span>
                      <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                          d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                      </svg>
                    </a>
                  <?php endif; ?>

                  <!-- Modal Details Button -->
                  <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.7rem; padding:2px 6px;"
                    onclick='openAdvisoryModal(<?= json_encode($adv, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                    Details &amp; Remediation &rarr;
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

<!-- ── Modal: Advisory Details & Remediation ── -->
<div id="advisoryModal" class="modal"
  style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
  <div class="card card-glass"
    style="width:90%; max-width:680px; max-height:90vh; overflow-y:auto; padding:24px; position:relative; border:1px solid var(--color-border); box-shadow:0 20px 40px rgba(0,0,0,0.5);">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
      <div>
        <div style="display:flex; align-items:center; gap:8px;">
          <h2 id="modalCveId"
            style="font-size:1.25rem; font-weight:700; color:var(--text-primary); margin:0; font-family:monospace;">
            CVE-XXXX-XXXX</h2>
          <span id="modalSeverityBadge" class="badge" style="font-weight:700; font-size:0.75rem;">SEVERITY</span>
        </div>
        <div id="modalTitle" style="font-size:0.9rem; font-weight:600; color:var(--text-secondary); margin-top:4px;">
          Title</div>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeAdvisoryModal()"
        style="font-size:1.2rem; line-height:1; padding:4px 8px;">&times;</button>
    </div>

    <div
      style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; font-size:0.8rem; background:var(--color-surface-2); padding:12px; border-radius:var(--radius-sm);">
      <div>
        <span style="color:var(--text-muted);">Package:</span>
        <strong id="modalPackage" style="color:var(--text-primary); font-family:monospace;">package</strong>
      </div>
      <div>
        <span style="color:var(--text-muted);">CVSS Risk Score:</span>
        <strong id="modalCvss" style="color:var(--text-primary);">0.0</strong>
      </div>
      <div>
        <span style="color:var(--text-muted);">Installed Version:</span>
        <code id="modalInstalledVer" style="color:#f43f5e;">version</code>
      </div>
      <div>
        <span style="color:var(--text-muted);">Fixed Version:</span>
        <code id="modalFixedVer" style="color:#10b981;">version</code>
      </div>
      <div>
        <span style="color:var(--text-muted);">Attack Vector:</span>
        <strong id="modalVector" style="color:var(--text-primary);">NETWORK</strong>
      </div>
      <div>
        <span style="color:var(--text-muted);">Published Date:</span>
        <span id="modalPublished" style="color:var(--text-primary);">date</span>
      </div>
    </div>

    <!-- Description -->
    <div style="margin-bottom:16px;">
      <h4
        style="font-size:0.8rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin:0 0 6px 0;">
        Vulnerability Description</h4>
      <div id="modalSummary"
        style="font-size:0.85rem; color:var(--text-secondary); line-height:1.5; background:var(--color-surface-1); padding:10px 12px; border-radius:var(--radius-sm); border:1px solid var(--color-border);">
        Summary...
      </div>
    </div>

    <!-- Remediation Instructions -->
    <div style="margin-bottom:20px;">
      <h4
        style="font-size:0.8rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin:0 0 6px 0;">
        Remediation Command (Root Terminal)</h4>
      <div
        style="display:flex; align-items:center; gap:8px; background:#0f172a; padding:10px 14px; border-radius:var(--radius-sm); border:1px solid #1e293b;">
        <code id="modalRemediation"
          style="flex:1; color:#38bdf8; font-family:monospace; font-size:0.8rem; overflow-x:auto;">apt update &amp;&amp; apt install ...</code>
        <button type="button" class="btn btn-ghost btn-sm" onclick="copyModalRemediation()" title="Copy to clipboard">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" />
          </svg>
        </button>
      </div>
    </div>

    <!-- External Links -->
    <div style="display:flex; justify-content:flex-end; gap:8px;">
      <a id="modalNvdLink" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-primary"
        style="display:inline-flex; align-items:center; gap:6px;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
        </svg>
        View on NIST NVD Document
      </a>
      <button type="button" class="btn btn-ghost" onclick="closeAdvisoryModal()">Close</button>
    </div>
  </div>
</div>

<!-- ── Modal: Scanner Info & System Baseline ── -->
<div id="sysModal" class="modal"
  style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
  <div class="card card-glass"
    style="width:90%; max-width:540px; padding:24px; position:relative; border:1px solid var(--color-border);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h3 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin:0;">Authenticated Scanner Info
      </h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeSysModal()">&times;</button>
    </div>
    <div style="font-size:0.85rem; line-height:1.6; color:var(--text-secondary);">
      <p>The MicronOS CVE vulnerability scanner runs authenticated package auditing against Debian package states and
        binary packages installed on this firewall appliance.</p>
      <div style="background:var(--color-surface-2); padding:12px; border-radius:var(--radius-sm); margin:12px 0;">
        <div><strong>Operating System:</strong> <?= esc($systemInfo['os']) ?></div>
        <div><strong>Active Kernel:</strong> <?= esc($systemInfo['kernel']) ?></div>
        <div><strong>System Architecture:</strong> <?= esc($systemInfo['arch']) ?></div>
        <div><strong>Total System Packages:</strong> <?= number_format($stats['total_packages']) ?> installed</div>
        <div><strong>Auditor Privilege:</strong> Authenticated Root Local State</div>
      </div>
      <p style="font-size:0.8rem; color:var(--text-muted); margin:0;">Advisory feeds are synchronized with NIST NVD
        (National Vulnerability Database, operated by the U.S. National Institute of Standards and Technology) and the
        Debian Security Bug Tracker.</p>
    </div>
    <div style="margin-top:20px; text-align:right;">
      <button type="button" class="btn btn-primary" onclick="closeSysModal()">OK</button>
    </div>
  </div>
</div>

<script>
  function showScanLoading(form) {
    const btn = document.getElementById('btnRunScan');
    const txt = document.getElementById('btnRunScanText');
    if (btn) {
      btn.disabled = true;
      btn.style.opacity = '0.7';
    }
    if (txt) {
      txt.textContent = 'Scanning Packages as Root...';
    }
  }

  function openAdvisoryModal(adv) {
    document.getElementById('modalCveId').textContent = adv.id || '';
    document.getElementById('modalTitle').textContent = adv.title || '';
    document.getElementById('modalPackage').textContent = adv.package_name || '';
    document.getElementById('modalCvss').textContent = (adv.cvss_score ? Number(adv.cvss_score).toFixed(1) : '5.0') + ' (' + (adv.severity || '') + ')';
    document.getElementById('modalInstalledVer').textContent = adv.installed_version || 'N/A';
    document.getElementById('modalFixedVer').textContent = adv.fixed_version || 'Pending upstream patch';
    document.getElementById('modalVector').textContent = adv.attack_vector || 'NETWORK';
    document.getElementById('modalPublished').textContent = adv.published_at || 'Recent';
    document.getElementById('modalSummary').textContent = adv.summary || 'No detailed description provided.';
    document.getElementById('modalRemediation').textContent = adv.remediation || ('apt update && apt install --only-upgrade ' + (adv.package_name || ''));
    document.getElementById('modalNvdLink').href = adv.nvd_url || ('https://nvd.nist.gov/vuln/detail/' + adv.id);

    const badge = document.getElementById('modalSeverityBadge');
    if (badge) {
      badge.textContent = adv.severity || 'MEDIUM';
      badge.className = 'badge ' + (adv.severity === 'CRITICAL' ? 'badge-danger' : (adv.severity === 'HIGH' ? 'badge-warning' : 'badge-info'));
    }

    const modal = document.getElementById('advisoryModal');
    if (modal) {
      modal.style.display = 'flex';
    }
  }

  function closeAdvisoryModal() {
    const modal = document.getElementById('advisoryModal');
    if (modal) {
      modal.style.display = 'none';
    }
  }

  function copyModalRemediation() {
    const code = document.getElementById('modalRemediation');
    if (code && code.textContent) {
      navigator.clipboard.writeText(code.textContent).then(() => {
        alert('Remediation command copied to clipboard: ' + code.textContent);
      });
    }
  }

  function openSysModal() {
    const m = document.getElementById('sysModal');
    if (m) m.style.display = 'flex';
  }

  function closeSysModal() {
    const m = document.getElementById('sysModal');
    if (m) m.style.display = 'none';
  }

  window.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeAdvisoryModal();
      closeSysModal();
    }
  });
</script>

<?= $this->endSection() ?>