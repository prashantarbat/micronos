<?php
/**
 * lightsquid page — the Class B plugin's own UI surface.
 *
 * Rendered by `Plugins::show()` in place of the generic metadata page, so an
 * installed plugin's nav entry ("Squid Reports") lands on something usable.
 *
 * Lightsquid is an offline Squid access-log reporter: it contributes no daemon
 * and opens no listener. Its settings live in a config file and its schedule in
 * a cron drop-in, so everything here is a *request*: the daemon fixes the plugin
 * id and validates every value before it writes a byte.
 *
 * @var array<string, mixed> $plugin
 */
$statusUrl  = base_url('plugins/lightsquid/status');
$applyUrl   = base_url('plugins/lightsquid/apply');
$refreshUrl = base_url('plugins/lightsquid/refresh');
?>
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.ls-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; }
.ls-meta dt { color:var(--text-muted); font-size:0.7rem; text-transform:uppercase; font-weight:600; letter-spacing:0.04em; margin-bottom:2px; }
.ls-meta dd { margin:0; color:var(--text-primary); font-family:var(--font-mono); font-size:.85rem; font-weight:600; word-break:break-all; }
.ls-led { display:inline-block; width:10px; height:10px; border-radius:50%; background:#6b7280; box-shadow:0 0 6px rgba(0,0,0,.4); }
.ls-led.on { background:#22c55e; box-shadow:0 0 8px rgba(34,197,94,.65); }
.ls-led.off { background:#ef4444; box-shadow:0 0 8px rgba(239,68,68,.5); }
.ls-field-hint { font-size:.7rem; color:var(--text-muted); margin-top:3px; }
details.ls-advanced { border:1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius:8px; padding:12px 14px; background:rgba(0,0,0,0.12); margin-top:16px; }
details.ls-advanced > summary { cursor:pointer; font-weight:600; font-size:0.82rem; color:var(--text-muted); display:flex; align-items:center; gap:8px; list-style:none; user-select:none; }
details.ls-advanced > summary::-webkit-details-marker { display:none; }
details.ls-advanced > summary:hover { color:var(--text-primary); }
details.ls-advanced[open] > summary { margin-bottom:14px; color:var(--text-primary); }
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
        <span style="color:var(--text-primary); font-weight:600;">Lightsquid Reports</span>
      </div>
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">lightsquid</h1>
        <span class="badge badge-success" style="font-size:0.7rem;">CLASS B</span>
        <span class="badge badge-muted" style="font-size:0.7rem;"><?= esc($plugin['id']) ?> · v<?= esc($plugin['version']) ?></span>
        <span id="lsStateBadge" class="badge badge-muted" style="font-size:0.7rem;">CHECKING…</span>
      </div>
      <p class="page-subtitle" style="margin-top:6px;">
        High-performance Squid proxy access-log analyzer. Generates traffic analytics, user consumption breakdowns, and website usage statistics.
      </p>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <a id="lsOpenConsoleBtn" href="<?= base_url('lightsquid') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px; background:linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); border-color:#0284c7; box-shadow:0 4px 14px rgba(2,132,199,0.35); font-weight:600;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
        </svg>
        Open LightSquid Console
      </a>
      <button type="button" class="btn btn-ghost btn-sm" onclick="lsRun('today')" style="display:inline-flex; align-items:center; gap:6px;" title="Parse today's proxy log entries now">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Refresh Reports Now
      </button>
      <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
      <?= \App\Libraries\ServiceStatus::widget('squid') ?>
    </div>
  </div>
</div>

<!-- ── Quick Metrics Hero ── -->
<div class="card card-glass animate-in animate-in-delay-1" style="padding:16px 20px; margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
    <div style="display:flex; align-items:center; gap:10px;">
      <span class="ls-led" id="lsLed"></span>
      <div style="font-weight:700; font-size:0.95rem; color:var(--text-primary);">Reporter Status &amp; Metrics</div>
      <span id="lsStatusNote" style="font-size:.74rem; color:var(--text-muted);"></span>
    </div>
    <div style="display:flex; gap:8px;">
      <button type="button" class="btn btn-ghost btn-xs" onclick="lsRefresh()" style="font-size:0.75rem;">Check Status</button>
      <button type="button" class="btn btn-ghost btn-xs" onclick="lsRun('full')" style="font-size:0.75rem;" title="Parse all rotated log archives">Full Re-index</button>
    </div>
  </div>

  <div class="ls-grid">
    <div style="padding:12px; background:rgba(255,255,255,0.02); border-radius:8px; border:1px solid rgba(255,255,255,0.04);">
      <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Reports On Disk</div>
      <div id="lsReportCount" style="font-size:1.35rem; font-weight:700; color:var(--text-primary); margin-top:4px;">—</div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">Generated HTML archives</div>
    </div>

    <div style="padding:12px; background:rgba(255,255,255,0.02); border-radius:8px; border:1px solid rgba(255,255,255,0.04);">
      <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Latest Report</div>
      <div id="lsReportNewest" style="font-size:1.05rem; font-weight:700; color:var(--text-primary); margin-top:4px; font-family:var(--font-mono);">—</div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">Most recent run timestamp</div>
    </div>

    <div style="padding:12px; background:rgba(255,255,255,0.02); border-radius:8px; border:1px solid rgba(255,255,255,0.04);">
      <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Auto-Refresh Schedule</div>
      <div id="lsSchedule" style="font-size:1.05rem; font-weight:700; color:var(--text-primary); margin-top:4px;">—</div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">Cron background generation</div>
    </div>

    <div style="padding:12px; background:rgba(255,255,255,0.02); border-radius:8px; border:1px solid rgba(255,255,255,0.04);">
      <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Configuration State</div>
      <div id="lsConfig" style="font-size:1.05rem; font-weight:700; color:var(--text-primary); margin-top:4px;">—</div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;" id="lsApplied">Last applied: —</div>
    </div>
  </div>

  <div id="lsWarnings" style="margin-top:10px; font-size:.75rem; color:#f59e0b;"></div>
  <div id="lsSteps" style="margin-top:6px; font-size:.72rem; color:var(--text-muted); font-family:var(--font-mono);"></div>
</div>

<!-- ── Streamlined Settings Card ── -->
<div class="card card-glass animate-in animate-in-delay-2" style="padding:20px; margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
    <div>
      <h2 style="font-weight:700; font-size:1rem; margin:0; color:var(--text-primary);">Reporter Settings</h2>
      <div style="font-size:.76rem; color:var(--text-muted); margin-top:2px;">
        Control automated report generation cadence and display options.
      </div>
    </div>
    <a href="<?= base_url('lightsquid') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-xs" style="color:var(--color-cyan, #06b6d4); display:inline-flex; align-items:center; gap:4px;">
      View Console &#8599;
    </a>
  </div>

  <form id="lsForm" onsubmit="return lsApply(event)">
    <!-- Primary Options: The 2-3 settings everyday users need -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px; margin-bottom:14px;">
      <div style="padding:12px 14px; background:rgba(255,255,255,0.02); border-radius:8px; border:1px solid rgba(255,255,255,0.06);">
        <label for="lsEnabled" style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:600; color:var(--text-primary);">
          <input type="checkbox" id="lsEnabled" name="enabled" checked style="width:18px; height:18px;">
          <span>lightsquid reports enabled</span>
        </label>
        <div class="ls-field-hint" style="margin-left:28px;">Generate Squid reports on schedule and allow console browsing.</div>
      </div>

      <div>
        <label for="lsSchedule" style="font-weight:600; font-size:0.82rem;">Refresh schedule</label>
        <select id="lsSchedule" name="schedule" class="form-control" style="margin-top:4px;">
          <option value="none">none — generate on demand only</option>
          <option value="10m">every 10 minutes</option>
          <option value="20m">every 20 minutes</option>
          <option value="30m">every 30 minutes</option>
          <option value="40m">every 40 minutes</option>
          <option value="50m">every 50 minutes</option>
          <option value="60m" selected>every 60 minutes</option>
          <option value="2h">every 2 hours</option>
          <option value="3h">every 3 hours</option>
          <option value="4h">every 4 hours</option>
          <option value="6h">every 6 hours</option>
          <option value="8h">every 8 hours</option>
          <option value="12h">every 12 hours</option>
          <option value="24h">daily at 23:45</option>
        </select>
        <div class="ls-field-hint">How often Squid access logs are processed into HTML reports.</div>
      </div>

      <div>
        <label for="lsLang" style="font-weight:600; font-size:0.82rem;">Report language</label>
        <select id="lsLang" name="lang" class="form-control" style="margin-top:4px;">
          <option value="eng" selected>English</option>
          <option value="bg">Bulgarian</option>
          <option value="cz">Czech</option>
          <option value="fr">French</option>
          <option value="hu">Hungarian</option>
          <option value="it">Italian</option>
          <option value="pt_br">Portuguese (Brazilian)</option>
          <option value="ru">Russian</option>
          <option value="ru-koi8">Russian (KOI-8)</option>
          <option value="sp">Spanish</option>
          <option value="ua">Ukrainian</option>
        </select>
        <div class="ls-field-hint">Language used in the generated HTML report pages.</div>
      </div>
    </div>

    <!-- Advanced Collapsible Options (Hidden by default to keep the UI clean) -->
    <details class="ls-advanced">
      <summary>
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
        <span>Advanced Options (Theme, Resolvers, Skip Lists &amp; Storage Paths)</span>
      </summary>

      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:14px; margin-top:12px;">
        <div>
          <label for="lsTemplate" style="font-weight:600; font-size:0.8rem;">Report template</label>
          <select id="lsTemplate" name="template" class="form-control" style="margin-top:4px;">
            <option value="base" selected>Base</option>
            <option value="text">Text</option>
            <option value="novosea">NovoSea</option>
            <option value="novopf">NovoPf</option>
          </select>
          <div class="ls-field-hint">Layout theme the report is rendered into.</div>
        </div>

        <div>
          <label for="lsBarcolor" style="font-weight:600; font-size:0.8rem;">Bar colour</label>
          <select id="lsBarcolor" name="barcolor" class="form-control" style="margin-top:4px;">
            <option value="orange" selected>Orange</option>
            <option value="blue">Blue</option>
            <option value="green">Green</option>
            <option value="yellow">Yellow</option>
            <option value="brown">Brown</option>
            <option value="red">Red</option>
          </select>
          <div class="ls-field-hint">Colour of the bandwidth traffic bars.</div>
        </div>

        <div>
          <label for="lsIp2name" style="font-weight:600; font-size:0.8rem;">IP resolve method</label>
          <select id="lsIp2name" name="ip2name" class="form-control" style="margin-top:4px;">
            <option value="dns" selected>DNS — resolve via reverse DNS</option>
            <option value="ip">IP — do not resolve</option>
            <option value="demo">Demo — AUTHNAME, DNSNAME, IP</option>
            <option value="simple">Simple — AUTHNAME, IP</option>
            <option value="smb">SMB — NetBIOS name</option>
            <option value="squidauth">SquidAuth — AUTHNAME, IP (i18n)</option>
          </select>
          <div class="ls-field-hint">How report IPs are converted into hostnames.</div>
        </div>

        <div>
          <label for="lsSkipurl" style="font-weight:600; font-size:0.8rem;">Skip URL(s)</label>
          <input type="text" id="lsSkipurl" name="skipurl" class="form-control" placeholder="example.com|192.168.1.|example.net" spellcheck="false" style="margin-top:4px;">
          <div class="ls-field-hint">Sites to omit from statistics (separated by <code>|</code>).</div>
        </div>

        <div>
          <label for="lsLogDir" style="font-weight:600; font-size:0.8rem;">Squid log directory</label>
          <input type="text" id="lsLogDir" name="log_dir" value="/var/log/squid" spellcheck="false" class="form-control" style="margin-top:4px;">
          <div class="ls-field-hint">Where Squid writes <code>access.log</code>. Absolute path.</div>
        </div>

        <div>
          <label for="lsReportDir" style="font-weight:600; font-size:0.8rem;">Report directory</label>
          <input type="text" id="lsReportDir" name="report_dir" value="/var/lightsquid/report" spellcheck="false" class="form-control" style="margin-top:4px;">
          <div class="ls-field-hint">Where static HTML reports are stored on disk.</div>
        </div>
      </div>
    </details>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; flex-wrap:wrap; gap:12px;">
      <div style="display:flex; gap:10px;">
        <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); border-color:#0284c7;">
          Apply Settings
        </button>
        <button type="button" class="btn btn-ghost" onclick="lsRun('today')">
          Refresh Today's Data
        </button>
      </div>
      <div id="lsFormNote" style="font-size:.82rem; font-weight:600;"></div>
    </div>
  </form>
</div>

<script>
const LS_STATUS_URL  = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
const LS_APPLY_URL   = <?= json_encode($applyUrl, JSON_UNESCAPED_SLASHES) ?>;
const LS_REFRESH_URL = <?= json_encode($refreshUrl, JSON_UNESCAPED_SLASHES) ?>;
let LS_CSRF = <?= json_encode(csrf_hash()) ?>;

function lsLed(el, on) {
  if (!el) return;
  el.classList.remove('on', 'off');
  el.classList.add(on ? 'on' : 'off');
}

function lsSet(id, value, fallback) {
  const el = document.getElementById(id);
  if (el) el.textContent = (value === undefined || value === null || value === '') ? (fallback || '—') : value;
}

function lsRender(result) {
  const data = (result && result.data) || {};
  const badge = document.getElementById('lsStateBadge');
  const configured = data.config_present === true;
  const scheduled = data.cron_present === true;

  if (badge) {
    badge.textContent = configured ? (scheduled ? 'SCHEDULED' : 'ON DEMAND') : 'NOT CONFIGURED';
    badge.className = 'badge ' + (configured ? (scheduled ? 'badge-success' : 'badge-warning') : 'badge-muted');
  }
  lsLed(document.getElementById('lsLed'), configured && scheduled);

  lsSet('lsConfig', data.config_present ? 'Active' : 'Not Generated', 'Not Generated');
  lsSet('lsSchedule', scheduled ? (data.schedule || 'Configured') : 'On Demand', 'None');
  lsSet('lsReportDirState', data.report_dir, '—');
  lsSet('lsReportCount', data.report_count ? data.report_count + ' Reports' : '0 Reports', '0 Reports');
  lsSet('lsReportNewest', data.report_newest, data.report_count ? '—' : 'None yet');
  lsSet('lsApplied', data.applied_at ? 'Applied: ' + data.applied_at : (data.config_present ? '—' : 'Never applied'));

  // Update console button href
  const consoleBtn = document.getElementById('lsOpenConsoleBtn');
  if (consoleBtn) {
    consoleBtn.href = <?= json_encode(base_url('lightsquid')) ?>;
  }

  // Pre-fill the form from applied settings
  if (data.lang && document.getElementById('lsLang')) document.getElementById('lsLang').value = data.lang;
  if (data.template && document.getElementById('lsTemplate')) document.getElementById('lsTemplate').value = data.template;
  if (data.barcolor && document.getElementById('lsBarcolor')) document.getElementById('lsBarcolor').value = data.barcolor;
  if (data.ip2name && document.getElementById('lsIp2name')) document.getElementById('lsIp2name').value = data.ip2name;
  if (data.skipurl !== undefined && document.getElementById('lsSkipurl')) document.getElementById('lsSkipurl').value = data.skipurl || '';
  if (data.log_dir && document.getElementById('lsLogDir')) document.getElementById('lsLogDir').value = data.log_dir;
  if (data.report_dir && document.getElementById('lsReportDir')) document.getElementById('lsReportDir').value = data.report_dir;
  if (data.schedule && document.getElementById('lsSchedule')) document.getElementById('lsSchedule').value = data.schedule;
  if (data.enabled !== undefined && document.getElementById('lsEnabled')) document.getElementById('lsEnabled').checked = data.enabled === true;

  const warnings = (result && result.warnings) || [];
  const warnEl = document.getElementById('lsWarnings');
  if (warnEl) warnEl.innerHTML = warnings.map(w => '⚠ ' + w).join('<br>');
  const steps = (result && result.steps) || [];
  const stepsEl = document.getElementById('lsSteps');
  if (stepsEl) stepsEl.innerHTML = steps.map(s => '· ' + s).join('<br>');
}

async function lsCall(url, options) {
  const response = await MicronScheduler.fetch(url, options);
  return response.json();
}

async function lsRefresh() {
  const note = document.getElementById('lsStatusNote');
  if (note) note.textContent = 'reading…';
  try {
    const body = await lsCall(LS_STATUS_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (body.csrf) LS_CSRF = body.csrf;
    lsRender(body.result);
    if (note) note.textContent = body.ok ? '' : (body.message || 'the daemon rejected the request');
  } catch (e) {
    if (note) note.textContent = 'status unavailable';
  }
}

async function lsApply(event) {
  event.preventDefault();
  const note = document.getElementById('lsStatusNote');
  const formNote = document.getElementById('lsFormNote');
  if (note) note.textContent = 'applying…';
  if (formNote) {
    formNote.textContent = 'Saving settings…';
    formNote.style.color = 'var(--text-muted)';
  }

  const body = await lsCall(LS_APPLY_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': LS_CSRF },
    body: JSON.stringify({
      enabled: document.getElementById('lsEnabled').checked,
      lang: document.getElementById('lsLang').value,
      template: document.getElementById('lsTemplate').value,
      barcolor: document.getElementById('lsBarcolor').value,
      ip2name: document.getElementById('lsIp2name').value,
      skipurl: document.getElementById('lsSkipurl').value.trim(),
      log_dir: document.getElementById('lsLogDir').value.trim(),
      report_dir: document.getElementById('lsReportDir').value.trim(),
      schedule: document.getElementById('lsSchedule').value,
    }),
  });
  if (body.csrf) LS_CSRF = body.csrf;

  if (formNote) {
    formNote.textContent = body.ok ? (body.message || 'Settings applied successfully.') : ('Failed: ' + (body.message || 'unknown error'));
    formNote.style.color = body.ok ? '#22c55e' : '#ef4444';
  }
  if (note) note.textContent = body.ok ? '' : 'apply failed';
  if (body.result) lsRender(body.result);
  return false;
}

async function lsRun(mode) {
  const note = document.getElementById('lsStatusNote');
  const formNote = document.getElementById('lsFormNote');
  if (note) note.textContent = 'parsing…';
  if (formNote) {
    formNote.textContent = 'Generating reports (' + mode + ')…';
    formNote.style.color = 'var(--text-muted)';
  }

  const body = await lsCall(LS_REFRESH_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': LS_CSRF },
    body: JSON.stringify({ mode: mode }),
  });
  if (body.csrf) LS_CSRF = body.csrf;

  if (formNote) {
    formNote.textContent = body.ok ? (body.message || 'Reports updated successfully.') : ('Failed: ' + (body.message || 'unknown error'));
    formNote.style.color = body.ok ? '#22c55e' : '#ef4444';
  }
  if (note) note.textContent = '';
  if (body.result) lsRender(body.result);
  lsRefresh();
}

lsRefresh();
</script>

<?= $this->endSection() ?>
