<?php
/**
 * darkstat page — the Class B plugin's own UI surface.
 *
 * Rendered by `Plugins::show()` in place of the generic metadata page, so an
 * installed plugin's nav entry lands on something usable.
 *
 * darkstat takes all of its settings on the command line, so a setting is a
 * change to the service's own ExecStart. Everything here is a *request*: the
 * daemon fixes the plugin id, validates every value (interface names, the port
 * range, the local networks, the BPF filter, the hosts caps) before it writes a
 * byte or runs a command, and the generated drop-in is what actually configures
 * the analyser. The page builds no command and writes no file.
 *
 * @var array<string, mixed> $plugin
 */
$statusUrl = base_url('plugins/darkstat/status');
$applyUrl  = base_url('plugins/darkstat/apply');
?>
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.ds-grid { display:grid; grid-template-columns:220px 1fr; gap:14px; }
@media (max-width: 820px) { .ds-grid { grid-template-columns:1fr; } }
.ds-meta dt { color:var(--text-muted); font-weight:600; }
.ds-meta dd { margin:0; color:var(--text-primary); font-family:var(--font-mono); font-size:.74rem; word-break:break-all; }
.ds-led { display:inline-block; width:9px; height:9px; border-radius:50%; background:#6b7280; box-shadow:0 0 6px rgba(0,0,0,.4); }
.ds-led.on { background:#22c55e; box-shadow:0 0 8px rgba(34,197,94,.65); }
.ds-led.off { background:#ef4444; box-shadow:0 0 8px rgba(239,68,68,.5); }
.ds-field-hint { font-size:.68rem; color:var(--text-muted); margin-top:2px; }
</style>

<div class="page-header animate-in">
  <div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <h1 class="page-title">darkstat</h1>
      <span class="badge badge-success" style="font-size:0.7rem;">CLASS B</span>
      <span class="badge badge-muted" style="font-size:0.7rem;"><?= esc($plugin['id']) ?> · v<?= esc($plugin['version']) ?></span>
      <span id="dsStateBadge" class="badge badge-muted" style="font-size:0.7rem;">CHECKING…</span>
    </div>
    <p class="page-subtitle">
      Per-interface traffic analyser with a built-in web UI and graphs. Read-only promiscuous capture; no changes to the
      forwarding path. Debian <code>darkstat</code>, configured here.
    </p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
    <button type="button" class="btn btn-ghost btn-sm" onclick="dsRefresh()">Refresh status</button>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:16px 18px; margin-bottom:16px;">
  <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
    <span class="ds-led" id="dsLed"></span>
    <div style="font-weight:700; font-size:0.92rem;">Analyser status</div>
    <span id="dsStatusNote" style="font-size:.74rem; color:var(--text-muted);"></span>
  </div>

  <div class="ds-grid">
    <dl class="ds-meta" style="display:grid; grid-template-columns:1fr; gap:3px;">
      <dt>darkstat service</dt><dd id="dsService">—</dd>
      <dt>Configuration drop-in</dt><dd id="dsDropin">—</dd>
      <dt>Capture interface(s)</dt><dd id="dsIfaces">—</dd>
      <dt>Web UI port</dt><dd id="dsPort">—</dd>
    </dl>
    <div>
      <dl class="ds-meta" style="display:grid; grid-template-columns:170px 1fr; gap:3px 12px;">
        <dt>Applied</dt><dd id="dsApplied">—</dd>
      </dl>
      <div id="dsWarnings" style="margin-top:10px; font-size:.72rem; color:#f59e0b;"></div>
      <div id="dsSteps" style="margin-top:8px; font-size:.7rem; color:var(--text-muted); font-family:var(--font-mono);"></div>
    </div>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px; margin-bottom:16px;">
  <div style="font-weight:700; font-size:0.92rem; margin-bottom:2px;">Settings</div>
  <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:12px;">
    darkstat is configured entirely on its command line, so applying these writes a systemd drop-in over the
    package's own <code>darkstat.service</code> and restarts it. The unit name stays the package's, so the daemon
    widget below (and the generic plugin status) still controls the same service. Port defaults to
    <strong>666</strong>; the local networks are what darkstat graphs beyond the host itself.
  </div>
  <form id="dsForm" onsubmit="return dsApply(event)">
    <div class="repo-grid">
      <div class="repo-field">
        <label for="dsInterface">Capture interface(s)</label>
        <input type="text" id="dsInterface" name="interface" placeholder="eth0" spellcheck="false" required>
        <div class="ds-field-hint">One name, or several separated by commas (e.g. <code>eth0, eth1</code>).</div>
      </div>
      <div class="repo-field">
        <label for="dsPortInput">Web UI port</label>
        <input type="number" id="dsPortInput" name="port" min="1" max="65535" value="666" required>
        <div class="ds-field-hint">Where the graphs are served. 1–65535.</div>
      </div>
      <div class="repo-field">
        <label for="dsLocalNets">Local networks</label>
        <input type="text" id="dsLocalNets" name="local_nets" placeholder="192.168.0.0/16, 10.0.0.0/8" spellcheck="false">
        <div class="ds-field-hint">CIDRs whose traffic is graphed, not just the host's own. Comma-separated.</div>
      </div>
      <div class="repo-field">
        <label for="dsFilter">Capture filter (BPF)</label>
        <input type="text" id="dsFilter" name="filter" placeholder="not arp" spellcheck="false">
        <div class="ds-field-hint">Optional tcpdump-style expression, e.g. <code>not arp</code> or <code>port 22</code>.</div>
      </div>
      <div class="repo-field">
        <label for="dsResolveDns" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="dsResolveDns" name="resolve_dns" checked>
          Resolve IPs to host names
        </label>
        <div class="ds-field-hint">Off passes <code>--no-dns</code> and saves the resolver's memory.</div>
      </div>
      <div class="repo-field">
        <label for="dsLocalOnly" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="dsLocalOnly" name="local_only">
          Show only local-network hosts
        </label>
        <div class="ds-field-hint">Passes <code>--local-only</code>; needs the local networks above.</div>
      </div>
      <div class="repo-field">
        <label for="dsChroot">Database directory (chroot)</label>
        <input type="text" id="dsChroot" name="chroot_dir" value="/var/lib/darkstat" spellcheck="false">
        <div class="ds-field-hint">Absolute path darkstat chroots into; the files below are relative to it.</div>
      </div>
      <div class="repo-field">
        <label for="dsDatabase">Database file</label>
        <input type="text" id="dsDatabase" name="database_file" value="darkstat.db" spellcheck="false">
        <div class="ds-field-hint">Saved on shutdown (<code>--export</code>) and loaded on start (<code>--import</code>).</div>
      </div>
      <div class="repo-field">
        <label for="dsDaylog">Daily log file</label>
        <input type="text" id="dsDaylog" name="daylog" placeholder="(off)" spellcheck="false">
        <div class="ds-field-hint">Optional <code>--daylog</code>; empty leaves logging off. Must be in the chroot.</div>
      </div>
      <div class="repo-field">
        <label for="dsHostsMax">Hosts kept in memory</label>
        <input type="number" id="dsHostsMax" name="hosts_max" min="2" max="100000" value="1000">
        <div class="ds-field-hint">Maximum hosts in the table (<code>--hosts-max</code>).</div>
      </div>
      <div class="repo-field">
        <label for="dsHostsKeep">Hosts retained when full</label>
        <input type="number" id="dsHostsKeep" name="hosts_keep" min="1" value="30">
        <div class="ds-field-hint">Kept when the table fills (<code>--hosts-keep</code>); must be less than the maximum.</div>
      </div>
      <div class="repo-field">
        <label for="dsEnabled" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="dsEnabled" name="enabled" checked>
          darkstat enabled
        </label>
        <div class="ds-field-hint">Unchecking removes the drop-in and stops the service.</div>
      </div>
    </div>
    <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary btn-sm">Apply settings</button>
    </div>
    <div id="dsFormNote" style="margin-top:10px; font-size:.74rem;"></div>
  </form>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px;">
  <div style="font-weight:700; font-size:0.9rem; margin-bottom:8px;">Diagnostics</div>
  <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:10px;">
    The analyser's own service unit is controllable here, and its journal is available beside it.
  </div>
  <?= \App\Libraries\ServiceStatus::widget(['darkstat'], ['darkstat' => 'darkstat Traffic Statistics']) ?>
</div>

<script>
const DS_STATUS_URL = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
const DS_APPLY_URL  = <?= json_encode($applyUrl, JSON_UNESCAPED_SLASHES) ?>;
let DS_CSRF = <?= json_encode(csrf_hash()) ?>;

function dsLed(el, on) {
  el.classList.remove('on', 'off');
  el.classList.add(on ? 'on' : 'off');
}

function dsSet(id, value, fallback) {
  document.getElementById(id).textContent = (value === undefined || value === null || value === '') ? (fallback || '—') : value;
}

function dsRender(result) {
  const data = (result && result.data) || {};
  const badge = document.getElementById('dsStateBadge');
  const running = data.service_state === 'running';

  badge.textContent = running ? (data.enabled === false ? 'STOPPED' : 'CAPTURING') : (data.dropin_present ? 'STOPPED' : 'NOT CONFIGURED');
  badge.className = 'badge ' + (running && data.enabled !== false ? 'badge-success' : (data.dropin_present ? 'badge-warning' : 'badge-muted'));
  dsLed(document.getElementById('dsLed'), running && data.enabled !== false);

  dsSet('dsService', data.service_state, '—');
  dsSet('dsDropin', data.dropin_present ? 'present' : 'absent', 'absent');
  dsSet('dsIfaces', (data.interfaces || []).join(', '), '—');
  dsSet('dsPort', data.port, '—');
  dsSet('dsApplied', data.applied_at, data.dropin_present ? '—' : 'never');

  // Pre-fill the form from the applied settings so a reload shows what is live.
  if (data.interfaces && data.interfaces.length) document.getElementById('dsInterface').value = data.interfaces.join(', ');
  if (data.port) document.getElementById('dsPortInput').value = data.port;
  if (data.local_nets) document.getElementById('dsLocalNets').value = data.local_nets.join(', ');
  if (data.filter) document.getElementById('dsFilter').value = data.filter;
  if (data.resolve_dns !== undefined) document.getElementById('dsResolveDns').checked = data.resolve_dns === true;
  if (data.local_only !== undefined) document.getElementById('dsLocalOnly').checked = data.local_only === true;
  if (data.chroot_dir) document.getElementById('dsChroot').value = data.chroot_dir;
  if (data.database_file) document.getElementById('dsDatabase').value = data.database_file;
  if (data.daylog !== undefined) document.getElementById('dsDaylog').value = data.daylog || '';
  if (data.hosts_max) document.getElementById('dsHostsMax').value = data.hosts_max;
  if (data.hosts_keep) document.getElementById('dsHostsKeep').value = data.hosts_keep;
  if (data.enabled !== undefined) document.getElementById('dsEnabled').checked = data.enabled === true;

  const warnings = (result && result.warnings) || [];
  document.getElementById('dsWarnings').innerHTML = warnings.map(w => '⚠ ' + w).join('<br>');
  const steps = (result && result.steps) || [];
  document.getElementById('dsSteps').innerHTML = steps.map(s => '· ' + s).join('<br>');
}

async function dsCall(url, options) {
  const response = await MicronScheduler.fetch(url, options);
  return response.json();
}

async function dsRefresh() {
  const note = document.getElementById('dsStatusNote');
  note.textContent = 'reading…';
  try {
    const body = await dsCall(DS_STATUS_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (body.csrf) DS_CSRF = body.csrf;
    dsRender(body.result);
    note.textContent = body.ok ? '' : (body.message || 'the daemon rejected the request');
  } catch (e) {
    note.textContent = 'status unavailable';
  }
}

async function dsApply(event) {
  event.preventDefault();
  const note = document.getElementById('dsStatusNote');
  const formNote = document.getElementById('dsFormNote');
  note.textContent = 'applying…';
  formNote.textContent = '';
  formNote.style.color = 'var(--text-muted)';

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

  formNote.textContent = body.ok ? (body.message || 'applied.') : ('failed: ' + (body.message || 'unknown error'));
  formNote.style.color = body.ok ? '#22c55e' : '#ef4444';
  note.textContent = body.ok ? '' : 'apply failed';
  if (body.result) dsRender(body.result);
  return false;
}

dsRefresh();
</script>

<?= $this->endSection() ?>
