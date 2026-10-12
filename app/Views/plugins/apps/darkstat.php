<?php
/**
 * darkstat page — the Class B plugin's own UI surface.
 *
 * Rendered by `Plugins::show()` in place of the generic metadata page, so an
 * installed plugin's nav entry lands on something usable.
 *
 * darkstat takes all of its settings on the command line, so a setting is a
 * change to the service's own ExecStart. Everything here is a *request*: the
 * daemon fixes the plugin id, validates every value before it writes a byte or
 * runs a command, and the generated drop-in configures the analyser.
 *
 * @var array<string, mixed> $plugin
 */
$statusUrl = base_url('plugins/darkstat/status');
$applyUrl  = base_url('plugins/darkstat/apply');
?>
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.ds-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; }
.ds-meta dt { color:var(--text-muted); font-size:0.7rem; text-transform:uppercase; font-weight:600; letter-spacing:0.04em; margin-bottom:2px; }
.ds-meta dd { margin:0; color:var(--text-primary); font-family:var(--font-mono); font-size:.85rem; font-weight:600; word-break:break-all; }
.ds-led { display:inline-block; width:10px; height:10px; border-radius:50%; background:#6b7280; box-shadow:0 0 6px rgba(0,0,0,.4); }
.ds-led.on { background:#22c55e; box-shadow:0 0 8px rgba(34,197,94,.65); }
.ds-led.off { background:#ef4444; box-shadow:0 0 8px rgba(239,68,68,.5); }
.ds-field-hint { font-size:.7rem; color:var(--text-muted); margin-top:3px; }
details.ds-advanced { border:1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius:8px; padding:12px 14px; background:rgba(0,0,0,0.12); margin-top:16px; }
details.ds-advanced > summary { cursor:pointer; font-weight:600; font-size:0.82rem; color:var(--text-muted); display:flex; align-items:center; gap:8px; list-style:none; user-select:none; }
details.ds-advanced > summary::-webkit-details-marker { display:none; }
details.ds-advanced > summary:hover { color:var(--text-primary); }
details.ds-advanced[open] > summary { margin-bottom:14px; color:var(--text-primary); }
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
        <span style="color:var(--text-primary); font-weight:600;">darkstat Traffic Analyser</span>
      </div>
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">darkstat</h1>
        <span class="badge badge-success" style="font-size:0.7rem;">CLASS B</span>
        <span class="badge badge-muted" style="font-size:0.7rem;"><?= esc($plugin['id']) ?> · v<?= esc($plugin['version']) ?></span>
        <span id="dsStateBadge" class="badge badge-muted" style="font-size:0.7rem;">CHECKING…</span>
      </div>
      <p class="page-subtitle" style="margin-top:6px;">
        Per-interface network traffic analyser with a built-in web dashboard, real-time graphs, and bandwidth breakdown per host.
      </p>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <a id="dsOpenConsoleBtn" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px; background:linear-gradient(135deg, #10b981 0%, #059669 100%); border-color:#059669; box-shadow:0 4px 14px rgba(16,185,129,0.35); font-weight:600;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
        </svg>
        Open darkstat Console
      </a>
      <button type="button" class="btn btn-ghost btn-sm" onclick="dsRefresh()">Refresh Status</button>
      <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
      <?= \App\Libraries\ServiceStatus::widget('darkstat') ?>
    </div>
  </div>
</div>

<!-- ── Quick Metrics Hero ── -->
<div class="card card-glass animate-in animate-in-delay-1" style="padding:16px 20px; margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
    <div style="display:flex; align-items:center; gap:10px;">
      <span class="ds-led" id="dsLed"></span>
      <div style="font-weight:700; font-size:0.95rem; color:var(--text-primary);">Analyser Status &amp; Web Listener</div>
      <span id="dsStatusNote" style="font-size:.74rem; color:var(--text-muted);"></span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted);" id="dsApplied">Last applied: —</div>
  </div>

  <div class="ds-grid">
    <div style="padding:12px; background:rgba(255,255,255,0.02); border-radius:8px; border:1px solid rgba(255,255,255,0.04);">
      <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Service State</div>
      <div id="dsService" style="font-size:1.25rem; font-weight:700; color:var(--text-primary); margin-top:4px;">—</div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">systemd daemon status</div>
    </div>

    <div style="padding:12px; background:rgba(255,255,255,0.02); border-radius:8px; border:1px solid rgba(255,255,255,0.04);">
      <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Capture Interface(s)</div>
      <div id="dsIfaces" style="font-size:1.15rem; font-weight:700; color:var(--text-primary); margin-top:4px; font-family:var(--font-mono);">—</div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">Network adapters monitored</div>
    </div>

    <div style="padding:12px; background:rgba(255,255,255,0.02); border-radius:8px; border:1px solid rgba(255,255,255,0.04);">
      <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Web Console Port</div>
      <div id="dsPort" style="font-size:1.25rem; font-weight:700; color:var(--color-emerald, #10b981); margin-top:4px; font-family:var(--font-mono);">—</div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">HTTP dashboard port</div>
    </div>

    <div style="padding:12px; background:rgba(255,255,255,0.02); border-radius:8px; border:1px solid rgba(255,255,255,0.04);">
      <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Drop-in Override</div>
      <div id="dsDropin" style="font-size:1.15rem; font-weight:700; color:var(--text-primary); margin-top:4px;">—</div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">systemd configuration file</div>
    </div>
  </div>

  <div id="dsWarnings" style="margin-top:10px; font-size:.75rem; color:#f59e0b;"></div>
  <div id="dsSteps" style="margin-top:6px; font-size:.72rem; color:var(--text-muted); font-family:var(--font-mono);"></div>
</div>

<!-- ── Streamlined Settings Card ── -->
<div class="card card-glass animate-in animate-in-delay-2" style="padding:20px; margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
    <div>
      <h2 style="font-weight:700; font-size:1rem; margin:0; color:var(--text-primary);">Traffic Analyser Settings</h2>
      <div style="font-size:.76rem; color:var(--text-muted); margin-top:2px;">
        Configure monitored network interfaces and web dashboard port.
      </div>
    </div>
    <span style="font-size:0.75rem; color:var(--text-muted);">Default Port: <strong>666</strong></span>
  </div>

  <form id="dsForm" onsubmit="return dsApply(event)">
    <!-- Primary Options: The essential 3 settings -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px; margin-bottom:14px;">
      <div style="padding:12px 14px; background:rgba(255,255,255,0.02); border-radius:8px; border:1px solid rgba(255,255,255,0.06);">
        <label for="dsEnabled" style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:600; color:var(--text-primary);">
          <input type="checkbox" id="dsEnabled" name="enabled" checked style="width:18px; height:18px;">
          <span>darkstat enabled</span>
        </label>
        <div class="ds-field-hint" style="margin-left:28px;">Capture packets in promiscuous mode and serve web graphs.</div>
      </div>

      <div>
        <label for="dsInterface" style="font-weight:600; font-size:0.82rem;">Capture interface(s) *</label>
        <input type="text" id="dsInterface" name="interface" placeholder="e.g. eth0 or eth0, eth1" spellcheck="false" required class="form-control" style="margin-top:4px;">
        <div class="ds-field-hint">One interface name, or several separated by commas.</div>
      </div>

      <div>
        <label for="dsPortInput" style="font-weight:600; font-size:0.82rem;">Web UI port *</label>
        <input type="number" id="dsPortInput" name="port" min="1" max="65535" value="666" required class="form-control" style="margin-top:4px;">
        <div class="ds-field-hint">Port where real-time traffic graphs are served (1–65535).</div>
      </div>
    </div>

    <!-- Advanced Collapsible Options (Hidden by default to keep the UI clean) -->
    <details class="ds-advanced">
      <summary>
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
        <span>Advanced Options (BPF Filter, Subnets, Host Caps &amp; Storage)</span>
      </summary>

      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px; margin-top:12px;">
        <div>
          <label for="dsLocalNets" style="font-weight:600; font-size:0.8rem;">Local networks</label>
          <input type="text" id="dsLocalNets" name="local_nets" placeholder="192.168.0.0/16, 10.0.0.0/8" spellcheck="false" class="form-control" style="margin-top:4px;">
          <div class="ds-field-hint">CIDRs whose traffic is graphed beyond the firewall host.</div>
        </div>

        <div>
          <label for="dsFilter" style="font-weight:600; font-size:0.8rem;">Capture filter (BPF)</label>
          <input type="text" id="dsFilter" name="filter" placeholder="not arp" spellcheck="false" class="form-control" style="margin-top:4px;">
          <div class="ds-field-hint">Optional tcpdump-style expression (e.g. <code>not arp</code>).</div>
        </div>

        <div>
          <label for="dsChroot" style="font-weight:600; font-size:0.8rem;">Database directory (chroot)</label>
          <input type="text" id="dsChroot" name="chroot_dir" value="/var/lib/darkstat" spellcheck="false" class="form-control" style="margin-top:4px;">
          <div class="ds-field-hint">Absolute sandbox path darkstat chroots into.</div>
        </div>

        <div>
          <label for="dsDatabase" style="font-weight:600; font-size:0.8rem;">Database file</label>
          <input type="text" id="dsDatabase" name="database_file" value="darkstat.db" spellcheck="false" class="form-control" style="margin-top:4px;">
          <div class="ds-field-hint">Saved on shutdown and restored on startup.</div>
        </div>

        <div>
          <label for="dsDaylog" style="font-weight:600; font-size:0.8rem;">Daily log file</label>
          <input type="text" id="dsDaylog" name="daylog" placeholder="(off)" spellcheck="false" class="form-control" style="margin-top:4px;">
          <div class="ds-field-hint">Optional daily rolling log file inside chroot.</div>
        </div>

        <div>
          <label for="dsHostsMax" style="font-weight:600; font-size:0.8rem;">Hosts kept in memory</label>
          <input type="number" id="dsHostsMax" name="hosts_max" min="2" max="100000" value="1000" class="form-control" style="margin-top:4px;">
          <div class="ds-field-hint">Maximum host records retained in table (<code>--hosts-max</code>).</div>
        </div>

        <div>
          <label for="dsHostsKeep" style="font-weight:600; font-size:0.8rem;">Hosts retained when full</label>
          <input type="number" id="dsHostsKeep" name="hosts_keep" min="1" value="30" class="form-control" style="margin-top:4px;">
          <div class="ds-field-hint">Number of top hosts kept when table fills.</div>
        </div>

        <div style="display:flex; flex-direction:column; gap:8px; justify-content:center;">
          <label for="dsResolveDns" style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem;">
            <input type="checkbox" id="dsResolveDns" name="resolve_dns" checked>
            <span>Resolve IPs to host names</span>
          </label>
          <label for="dsLocalOnly" style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem;">
            <input type="checkbox" id="dsLocalOnly" name="local_only">
            <span>Show only local-network hosts</span>
          </label>
        </div>
      </div>
    </details>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; flex-wrap:wrap; gap:12px;">
      <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg, #10b981 0%, #059669 100%); border-color:#059669;">
        Apply Settings
      </button>
      <div id="dsFormNote" style="font-size:.82rem; font-weight:600;"></div>
    </div>
  </form>
</div>

<script>
const DS_STATUS_URL = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
const DS_APPLY_URL  = <?= json_encode($applyUrl, JSON_UNESCAPED_SLASHES) ?>;
let DS_CSRF = <?= json_encode(csrf_hash()) ?>;

function dsLed(el, on) {
  if (!el) return;
  el.classList.remove('on', 'off');
  el.classList.add(on ? 'on' : 'off');
}

function dsSet(id, value, fallback) {
  const el = document.getElementById(id);
  if (el) el.textContent = (value === undefined || value === null || value === '') ? (fallback || '—') : value;
}

function dsRender(result) {
  const data = (result && result.data) || {};
  const badge = document.getElementById('dsStateBadge');
  const running = data.service_state === 'running';

  if (badge) {
    badge.textContent = running ? (data.enabled === false ? 'STOPPED' : 'CAPTURING') : (data.dropin_present ? 'STOPPED' : 'NOT CONFIGURED');
    badge.className = 'badge ' + (running && data.enabled !== false ? 'badge-success' : (data.dropin_present ? 'badge-warning' : 'badge-muted'));
  }
  dsLed(document.getElementById('dsLed'), running && data.enabled !== false);

  dsSet('dsService', data.service_state ? data.service_state.toUpperCase() : 'STOPPED', 'STOPPED');
  dsSet('dsDropin', data.dropin_present ? 'Active' : 'Not generated', 'Absent');
  dsSet('dsIfaces', (data.interfaces || []).join(', '), 'None');
  dsSet('dsPort', data.port ? data.port : '666', '666');
  dsSet('dsApplied', data.applied_at ? 'Applied: ' + data.applied_at : (data.dropin_present ? '—' : 'Never applied'));

  // Update console button href to point directly to darkstat web port on current host
  const port = data.port || document.getElementById('dsPortInput').value || 666;
  const consoleBtn = document.getElementById('dsOpenConsoleBtn');
  if (consoleBtn) {
    consoleBtn.href = window.location.protocol + '//' + window.location.hostname + ':' + port;
  }

  // Pre-fill the form from applied settings
  if (data.interfaces && data.interfaces.length && document.getElementById('dsInterface')) {
    document.getElementById('dsInterface').value = data.interfaces.join(', ');
  }
  if (data.port && document.getElementById('dsPortInput')) {
    document.getElementById('dsPortInput').value = data.port;
  }
  if (data.local_nets && document.getElementById('dsLocalNets')) {
    document.getElementById('dsLocalNets').value = data.local_nets.join(', ');
  }
  if (data.filter && document.getElementById('dsFilter')) {
    document.getElementById('dsFilter').value = data.filter;
  }
  if (data.resolve_dns !== undefined && document.getElementById('dsResolveDns')) {
    document.getElementById('dsResolveDns').checked = data.resolve_dns === true;
  }
  if (data.local_only !== undefined && document.getElementById('dsLocalOnly')) {
    document.getElementById('dsLocalOnly').checked = data.local_only === true;
  }
  if (data.chroot_dir && document.getElementById('dsChroot')) {
    document.getElementById('dsChroot').value = data.chroot_dir;
  }
  if (data.database_file && document.getElementById('dsDatabase')) {
    document.getElementById('dsDatabase').value = data.database_file;
  }
  if (data.daylog !== undefined && document.getElementById('dsDaylog')) {
    document.getElementById('dsDaylog').value = data.daylog || '';
  }
  if (data.hosts_max && document.getElementById('dsHostsMax')) {
    document.getElementById('dsHostsMax').value = data.hosts_max;
  }
  if (data.hosts_keep && document.getElementById('dsHostsKeep')) {
    document.getElementById('dsHostsKeep').value = data.hosts_keep;
  }
  if (data.enabled !== undefined && document.getElementById('dsEnabled')) {
    document.getElementById('dsEnabled').checked = data.enabled === true;
  }

  const warnings = (result && result.warnings) || [];
  const warnEl = document.getElementById('dsWarnings');
  if (warnEl) warnEl.innerHTML = warnings.map(w => '⚠ ' + w).join('<br>');
  const steps = (result && result.steps) || [];
  const stepsEl = document.getElementById('dsSteps');
  if (stepsEl) stepsEl.innerHTML = steps.map(s => '· ' + s).join('<br>');
}

async function dsCall(url, options) {
  const response = await MicronScheduler.fetch(url, options);
  return response.json();
}

async function dsRefresh() {
  const note = document.getElementById('dsStatusNote');
  if (note) note.textContent = 'reading…';
  try {
    const body = await dsCall(DS_STATUS_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (body.csrf) DS_CSRF = body.csrf;
    dsRender(body.result);
    if (note) note.textContent = body.ok ? '' : (body.message || 'the daemon rejected the request');
  } catch (e) {
    if (note) note.textContent = 'status unavailable';
  }
}

async function dsApply(event) {
  event.preventDefault();
  const note = document.getElementById('dsStatusNote');
  const formNote = document.getElementById('dsFormNote');
  if (note) note.textContent = 'applying…';
  if (formNote) {
    formNote.textContent = 'Applying settings…';
    formNote.style.color = 'var(--text-muted)';
  }

  const body = await dsCall(DS_APPLY_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': DS_CSRF },
    body: JSON.stringify({
      enabled: document.getElementById('dsEnabled').checked,
      interface: document.getElementById('dsInterface').value.trim(),
      port: parseInt(document.getElementById('dsPortInput').value, 10),
      local_nets: document.getElementById('dsLocalNets').value.trim(),
      filter: document.getElementById('dsFilter').value.trim(),
      resolve_dns: document.getElementById('dsResolveDns').checked,
      local_only: document.getElementById('dsLocalOnly').checked,
      chroot_dir: document.getElementById('dsChroot').value.trim(),
      database_file: document.getElementById('dsDatabase').value.trim(),
      daylog: document.getElementById('dsDaylog').value.trim(),
      hosts_max: parseInt(document.getElementById('dsHostsMax').value, 10),
      hosts_keep: parseInt(document.getElementById('dsHostsKeep').value, 10),
    }),
  });
  if (body.csrf) DS_CSRF = body.csrf;

  if (formNote) {
    formNote.textContent = body.ok ? (body.message || 'Settings applied and service updated.') : ('Failed: ' + (body.message || 'unknown error'));
    formNote.style.color = body.ok ? '#22c55e' : '#ef4444';
  }
  if (note) note.textContent = body.ok ? '' : 'apply failed';
  if (body.result) dsRender(body.result);
  return false;
}

dsRefresh();
</script>

<?= $this->endSection() ?>
