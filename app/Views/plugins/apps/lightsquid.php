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
 * id and validates every value (the language/template/colour/name sets, the
 * schedule, the directories and the skip list) before it writes a byte. The page
 * builds no command and writes no file.
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
.ls-grid { display:grid; grid-template-columns:220px 1fr; gap:14px; }
@media (max-width: 820px) { .ls-grid { grid-template-columns:1fr; } }
.ls-meta dt { color:var(--text-muted); font-weight:600; }
.ls-meta dd { margin:0; color:var(--text-primary); font-family:var(--font-mono); font-size:.74rem; word-break:break-all; }
.ls-led { display:inline-block; width:9px; height:9px; border-radius:50%; background:#6b7280; box-shadow:0 0 6px rgba(0,0,0,.4); }
.ls-led.on { background:#22c55e; box-shadow:0 0 8px rgba(34,197,94,.65); }
.ls-led.off { background:#ef4444; box-shadow:0 0 8px rgba(239,68,68,.5); }
.ls-field-hint { font-size:.68rem; color:var(--text-muted); margin-top:2px; }
</style>

<div class="page-header animate-in">
  <div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <h1 class="page-title">lightsquid</h1>
      <span class="badge badge-success" style="font-size:0.7rem;">CLASS B</span>
      <span class="badge badge-muted" style="font-size:0.7rem;"><?= esc($plugin['id']) ?> · v<?= esc($plugin['version']) ?></span>
      <span id="lsStateBadge" class="badge badge-muted" style="font-size:0.7rem;">CHECKING…</span>
    </div>
    <p class="page-subtitle">
      Offline Squid access-log reporter. Runs on a schedule and writes static HTML reports; it contributes no daemon
      and opens no listener. Configured here, like pfSense's Squid Proxy Reports page.
    </p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
    <button type="button" class="btn btn-ghost btn-sm" onclick="lsRefresh()">Refresh status</button>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:16px 18px; margin-bottom:16px;">
  <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
    <span class="ls-led" id="lsLed"></span>
    <div style="font-weight:700; font-size:0.92rem;">Reporter status</div>
    <span id="lsStatusNote" style="font-size:.74rem; color:var(--text-muted);"></span>
  </div>

  <div class="ls-grid">
    <dl class="ls-meta" style="display:grid; grid-template-columns:1fr; gap:3px;">
      <dt>Config file</dt><dd id="lsConfig">—</dd>
      <dt>Refresh schedule</dt><dd id="lsSchedule">—</dd>
      <dt>Report directory</dt><dd id="lsReportDirState">—</dd>
      <dt>Reports on disk</dt><dd id="lsReportCount">—</dd>
    </dl>
    <div>
      <dl class="ls-meta" style="display:grid; grid-template-columns:170px 1fr; gap:3px 12px;">
        <dt>Newest report</dt><dd id="lsReportNewest">—</dd>
        <dt>Applied</dt><dd id="lsApplied">—</dd>
      </dl>
      <div id="lsWarnings" style="margin-top:10px; font-size:.72rem; color:#f59e0b;"></div>
      <div id="lsSteps" style="margin-top:8px; font-size:.7rem; color:var(--text-muted); font-family:var(--font-mono);"></div>
    </div>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px; margin-bottom:16px;">
  <div style="font-weight:700; font-size:0.92rem; margin-bottom:2px;">Settings</div>
  <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:12px;">
    Lightsquid reads its settings from a generated <code>lightsquid.conf</code>; applying these writes that file and a
    <code>/etc/cron.d/lightsquid</code> drop-in so the reports stay fresh. There is no daemon and no listener — the
    reports are static HTML under the report directory.
  </div>
  <form id="lsForm" onsubmit="return lsApply(event)">
    <div class="repo-grid">
      <div class="repo-field">
        <label for="lsLang">Report language</label>
        <select id="lsLang" name="lang">
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
        <div class="ls-field-hint">Language of the generated report pages.</div>
      </div>
      <div class="repo-field">
        <label for="lsTemplate">Report template</label>
        <select id="lsTemplate" name="template">
          <option value="base" selected>Base</option>
          <option value="text">Text</option>
          <option value="novosea">NovoSea</option>
          <option value="novopf">NovoPf</option>
        </select>
        <div class="ls-field-hint">Layout the report is rendered into.</div>
      </div>
      <div class="repo-field">
        <label for="lsBarcolor">Bar colour</label>
        <select id="lsBarcolor" name="barcolor">
          <option value="orange" selected>Orange</option>
          <option value="blue">Blue</option>
          <option value="green">Green</option>
          <option value="yellow">Yellow</option>
          <option value="brown">Brown</option>
          <option value="red">Red</option>
        </select>
        <div class="ls-field-hint">Colour of the traffic bars.</div>
      </div>
      <div class="repo-field">
        <label for="lsIp2name">IP resolve method</label>
        <select id="lsIp2name" name="ip2name">
          <option value="dns" selected>DNS — resolve via reverse DNS</option>
          <option value="ip">IP — do not resolve</option>
          <option value="demo">Demo — AUTHNAME, DNSNAME, IP</option>
          <option value="simple">Simple — AUTHNAME, IP</option>
          <option value="smb">SMB — NetBIOS name</option>
          <option value="squidauth">SquidAuth — AUTHNAME, IP (i18n)</option>
        </select>
        <div class="ls-field-hint">How report IPs are turned into host names.</div>
      </div>
      <div class="repo-field">
        <label for="lsSkipurl">Skip URL(s)</label>
        <input type="text" id="lsSkipurl" name="skipurl" placeholder="example.com|192.168.1.|example.net" spellcheck="false">
        <div class="ls-field-hint">Sites to omit from the statistics, separated by <code>|</code>. Empty skips nothing.</div>
      </div>
      <div class="repo-field">
        <label for="lsLogDir">Squid log directory</label>
        <input type="text" id="lsLogDir" name="log_dir" value="/var/log/squid" spellcheck="false">
        <div class="ls-field-hint">Where Squid writes <code>access.log</code>. Absolute path.</div>
      </div>
      <div class="repo-field">
        <label for="lsReportDir">Report directory</label>
        <input type="text" id="lsReportDir" name="report_dir" value="/var/lightsquid/report" spellcheck="false">
        <div class="ls-field-hint">Where the static HTML reports are written. Absolute path.</div>
      </div>
      <div class="repo-field">
        <label for="lsSchedule">Refresh schedule</label>
        <select id="lsSchedule" name="schedule">
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
        <div class="ls-field-hint">How often the log is re-parsed (a cron drop-in).</div>
      </div>
      <div class="repo-field">
        <label for="lsEnabled" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="lsEnabled" name="enabled" checked>
          lightsquid reports enabled
        </label>
        <div class="ls-field-hint">Unchecking removes the config file and the schedule.</div>
      </div>
    </div>
    <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary btn-sm">Apply settings</button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="lsRun('today')">Refresh now</button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="lsRun('full')">Refresh full</button>
    </div>
    <div style="font-size:.68rem; color:var(--text-muted); margin-top:6px;">
      <strong>Refresh now</strong> re-parses today's entries in the current log; <strong>Refresh full</strong> re-parses
      all logs including the rotated ones, which may take a while.
    </div>
    <div id="lsFormNote" style="margin-top:10px; font-size:.74rem;"></div>
  </form>
</div>

<script>
const LS_STATUS_URL  = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
const LS_APPLY_URL   = <?= json_encode($applyUrl, JSON_UNESCAPED_SLASHES) ?>;
const LS_REFRESH_URL = <?= json_encode($refreshUrl, JSON_UNESCAPED_SLASHES) ?>;
let LS_CSRF = <?= json_encode(csrf_hash()) ?>;

function lsLed(el, on) {
  el.classList.remove('on', 'off');
  el.classList.add(on ? 'on' : 'off');
}

function lsSet(id, value, fallback) {
  document.getElementById(id).textContent = (value === undefined || value === null || value === '') ? (fallback || '—') : value;
}

function lsRender(result) {
  const data = (result && result.data) || {};
  const badge = document.getElementById('lsStateBadge');
  const configured = data.config_present === true;
  const scheduled = data.cron_present === true;

  badge.textContent = configured ? (scheduled ? 'SCHEDULED' : 'ON DEMAND') : 'NOT CONFIGURED';
  badge.className = 'badge ' + (configured ? (scheduled ? 'badge-success' : 'badge-warning') : 'badge-muted');
  lsLed(document.getElementById('lsLed'), configured && scheduled);

  lsSet('lsConfig', data.config_present ? 'present' : 'absent', 'absent');
  lsSet('lsSchedule', scheduled ? (data.schedule || 'configured') : 'none', 'none');
  lsSet('lsReportDirState', data.report_dir, '—');
  lsSet('lsReportCount', data.report_count, '0');
  lsSet('lsReportNewest', data.report_newest, data.report_count ? '—' : 'none yet');
  lsSet('lsApplied', data.applied_at, data.config_present ? '—' : 'never');

  // Pre-fill the form from the applied settings so a reload shows what is live.
  if (data.lang) document.getElementById('lsLang').value = data.lang;
  if (data.template) document.getElementById('lsTemplate').value = data.template;
  if (data.barcolor) document.getElementById('lsBarcolor').value = data.barcolor;
  if (data.ip2name) document.getElementById('lsIp2name').value = data.ip2name;
  if (data.skipurl !== undefined) document.getElementById('lsSkipurl').value = data.skipurl || '';
  if (data.log_dir) document.getElementById('lsLogDir').value = data.log_dir;
  if (data.report_dir) document.getElementById('lsReportDir').value = data.report_dir;
  if (data.schedule) document.getElementById('lsSchedule').value = data.schedule;
  if (data.enabled !== undefined) document.getElementById('lsEnabled').checked = data.enabled === true;

  const warnings = (result && result.warnings) || [];
  document.getElementById('lsWarnings').innerHTML = warnings.map(w => '⚠ ' + w).join('<br>');
  const steps = (result && result.steps) || [];
  document.getElementById('lsSteps').innerHTML = steps.map(s => '· ' + s).join('<br>');
}

async function lsCall(url, options) {
  const response = await MicronScheduler.fetch(url, options);
  return response.json();
}

async function lsRefresh() {
  const note = document.getElementById('lsStatusNote');
  note.textContent = 'reading…';
  try {
    const body = await lsCall(LS_STATUS_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (body.csrf) LS_CSRF = body.csrf;
    lsRender(body.result);
    note.textContent = body.ok ? '' : (body.message || 'the daemon rejected the request');
  } catch (e) {
    note.textContent = 'status unavailable';
  }
}

async function lsApply(event) {
  event.preventDefault();
  const note = document.getElementById('lsStatusNote');
  const formNote = document.getElementById('lsFormNote');
  note.textContent = 'applying…';
  formNote.textContent = '';
  formNote.style.color = 'var(--text-muted)';

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

  formNote.textContent = body.ok ? (body.message || 'applied.') : ('failed: ' + (body.message || 'unknown error'));
  formNote.style.color = body.ok ? '#22c55e' : '#ef4444';
  note.textContent = body.ok ? '' : 'apply failed';
  if (body.result) lsRender(body.result);
  return false;
}

async function lsRun(mode) {
  const note = document.getElementById('lsStatusNote');
  const formNote = document.getElementById('lsFormNote');
  note.textContent = 'parsing…';
  formNote.textContent = '';

  const body = await lsCall(LS_REFRESH_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': LS_CSRF },
    body: JSON.stringify({ mode: mode }),
  });
  if (body.csrf) LS_CSRF = body.csrf;

  formNote.textContent = body.ok ? (body.message || 'done.') : ('failed: ' + (body.message || 'unknown error'));
  formNote.style.color = body.ok ? '#22c55e' : '#ef4444';
  note.textContent = '';
  if (body.result) lsRender(body.result);
  lsRefresh();
}

lsRefresh();
</script>

<?= $this->endSection() ?>
