<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.lsc-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px; margin-bottom:20px; }
.lsc-stat-card {
  background:rgba(255,255,255,0.02); border:1px solid var(--color-border);
  border-radius:10px; padding:16px; display:flex; flex-direction:column; gap:6px;
}
.lsc-stat-label { font-size:0.7rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted); font-weight:600; }
.lsc-stat-val { font-size:1.15rem; font-weight:700; color:var(--text-primary); font-family:var(--font-mono); }
.lsc-frame-wrap {
  width:100%; min-height:680px; border:1px solid var(--color-border); border-radius:10px;
  background:#fff; overflow:hidden;
}
.lsc-frame { width:100%; height:680px; border:none; }
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="display:flex; align-items:center; gap:8px; font-size:0.75rem; color:var(--text-muted); margin-bottom:4px;">
        <a href="<?= base_url('dashboard') ?>" style="color:var(--text-muted); text-decoration:none;">Dashboard</a>
        <span>/</span>
        <a href="<?= base_url('plugins') ?>" style="color:var(--text-muted); text-decoration:none;">Plugins</a>
        <span>/</span>
        <a href="<?= esc($settingsUrl) ?>" style="color:var(--text-muted); text-decoration:none;">Lightsquid</a>
        <span>/</span>
        <span style="color:var(--text-primary); font-weight:600;">Reports Console</span>
      </div>
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">LightSquid Console</h1>
        <span class="badge badge-success" style="font-size:0.7rem;">REPORTS CONSOLE</span>
        <span class="badge badge-info" style="font-size:0.7rem;">SQUID ACCESS LOGS</span>
        <span id="lscStateBadge" class="badge <?= $hasReports ? 'badge-success' : 'badge-warning' ?>" style="font-size:0.7rem;">
          <?= $hasReports ? 'REPORTS READY' : 'NO REPORTS COMPILED' ?>
        </span>
      </div>
      <p class="page-subtitle" style="margin-top:6px;">
        Interactive web analytics and user consumption breakdowns generated from Squid proxy access logs.
      </p>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <button type="button" class="btn btn-primary btn-sm" onclick="lscRun('today')" id="lscBtnToday" style="display:inline-flex; align-items:center; gap:6px;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Refresh Today
      </button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="lscRun('full')" id="lscBtnFull" title="Parse all historical rotated access logs">
        Full Parse
      </button>
      <a href="<?= esc($settingsUrl) ?>" class="btn btn-ghost btn-sm">
        Settings
      </a>
      <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
      <?= \App\Libraries\ServiceStatus::widget('squid') ?>
    </div>
  </div>
</div>

<!-- ── Run Status Notice ── -->
<div id="lscNotice" class="alert alert-info animate-in" style="display:none; margin-bottom:18px;"></div>

<!-- ── Metrics Overview ── -->
<div class="card card-glass animate-in animate-in-delay-1" style="padding:18px 20px; margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
    <div style="display:flex; align-items:center; gap:8px;">
      <span style="display:inline-block; width:9px; height:9px; border-radius:50%; background:<?= $hasReports ? '#10b981' : '#f59e0b' ?>;"></span>
      <span style="font-weight:700; font-size:0.95rem; color:var(--text-primary);">Report Generation Engine</span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted);">
      Target directory: <code style="color:var(--color-cyan);"><?= esc($reportDir) ?></code>
    </div>
  </div>

  <div class="lsc-grid">
    <div class="lsc-stat-card">
      <span class="lsc-stat-label">Web Proxy Daemon</span>
      <span class="lsc-stat-val" style="color:var(--color-emerald, #10b981);">Squid Proxy</span>
      <span style="font-size:0.72rem; color:var(--text-muted);">Source of traffic telemetry logs</span>
    </div>

    <div class="lsc-stat-card">
      <span class="lsc-stat-label">Parser Schedule</span>
      <span class="lsc-stat-val" id="lscScheduleVal">Active</span>
      <span style="font-size:0.72rem; color:var(--text-muted);">Automated cron regeneration</span>
    </div>

    <div class="lsc-stat-card">
      <span class="lsc-stat-label">Report Compilation</span>
      <span class="lsc-stat-val" id="lscReportState"><?= $hasReports ? 'Available' : 'Pending First Run' ?></span>
      <span style="font-size:0.72rem; color:var(--text-muted);"><?= $hasReports ? 'Static HTML ready to view' : 'Click Refresh Today above to generate' ?></span>
    </div>
  </div>

  <?php if (! $hasReports): ?>
    <div style="padding:16px; border-radius:8px; background:rgba(6,182,212,0.06); border:1px solid rgba(6,182,212,0.2); display:flex; align-items:flex-start; gap:12px;">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan); flex-shrink:0; margin-top:2px;">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      <div>
        <div style="font-weight:700; font-size:0.9rem; color:var(--text-primary); margin-bottom:4px;">No compiled HTML reports generated yet</div>
        <div style="font-size:0.78rem; color:var(--text-secondary); line-height:1.5;">
          LightSquid operates in batches: it parses Squid's <code>/var/log/squid/access.log</code> on a timer (default: every 60m) or on-demand.
          To compile your first set of statistics right away, click the <strong>Refresh Today</strong> button in the top right. Once generated, reports will display here automatically.
        </div>
        <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
          <button type="button" class="btn btn-primary btn-sm" onclick="lscRun('today')">
            Generate Today's Report Now
          </button>
          <a href="<?= esc($settingsUrl) ?>" class="btn btn-ghost btn-sm">
            Configure Report Schedule &amp; Language
          </a>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div style="margin-top:10px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <span style="font-weight:600; font-size:0.85rem; color:var(--text-primary);">Embedded Reports View</span>
        <a href="<?= base_url('lightsquid/index.html') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-xs" style="color:var(--color-cyan);">
          Open Standalone Tab &#8599;
        </a>
      </div>
      <div class="lsc-frame-wrap">
        <iframe src="<?= base_url('lightsquid/index.html') ?>" class="lsc-frame" title="LightSquid Reports"></iframe>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
const LSC_REFRESH_URL = <?= json_encode($refreshUrl, JSON_UNESCAPED_SLASHES) ?>;
const LSC_STATUS_URL  = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
let LSC_CSRF = <?= json_encode(csrf_hash()) ?>;

async function lscRun(mode) {
  const notice = document.getElementById('lscNotice');
  const btn = mode === 'today' ? document.getElementById('lscBtnToday') : document.getElementById('lscBtnFull');
  if (btn) btn.disabled = true;

  if (notice) {
    notice.style.display = 'block';
    notice.className = 'alert alert-info animate-in';
    notice.textContent = 'Parsing Squid access logs (' + mode + ')... please wait.';
  }

  try {
    const res = await fetch(LSC_REFRESH_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': LSC_CSRF,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ mode: mode }),
    });
    const data = await res.json();
    if (data.csrf) LSC_CSRF = data.csrf;

    if (notice) {
      notice.className = 'alert ' + (data.ok ? 'alert-success' : 'alert-danger') + ' animate-in';
      notice.textContent = data.message || (data.ok ? 'Report generated successfully.' : 'Failed to parse reports.');
    }

    if (data.ok) {
      setTimeout(() => window.location.reload(), 1500);
    }
  } catch (err) {
    if (notice) {
      notice.className = 'alert alert-danger animate-in';
      notice.textContent = 'Network or server error while running report generator.';
    }
  } finally {
    if (btn) btn.disabled = false;
  }
}

// Fetch live status on load
async function lscLoadStatus() {
  try {
    const res = await fetch(LSC_STATUS_URL);
    const json = await res.json();
    if (json.csrf) LSC_CSRF = json.csrf;
    const data = (json && json.result && json.result.data) || {};
    if (data.schedule) {
      const schEl = document.getElementById('lscScheduleVal');
      if (schEl) schEl.textContent = 'Every ' + data.schedule;
    }
  } catch (e) {}
}
document.addEventListener('DOMContentLoaded', lscLoadStatus);
</script>

<?= $this->endSection() ?>
