<?php
/**
 * lldpd page — the Class B plugin's own UI surface.
 *
 * Rendered by `Plugins::show()` in place of the generic metadata page, so an
 * installed plugin's nav entry ("LLDP Neighbours") lands on something usable.
 * It carries both halves of pfSense's LLDP page: the settings form and the
 * neighbour table.
 *
 * Most of lldpd's options are command line, so a setting is a change to the
 * service's own ExecStart; the few only `lldpcli` can apply at start go in
 * `/etc/lldpd.conf`. Everything here is a *request*: the daemon fixes the plugin
 * id and validates every value (the interface pattern, the management-address
 * pattern, the free-form strings, the hostname, the transmit timing) before it
 * writes a byte or runs a command. The page builds no command and writes no file.
 *
 * @var array<string, mixed> $plugin
 */
$statusUrl     = base_url('plugins/lldpd/status');
$applyUrl      = base_url('plugins/lldpd/apply');
$neighboursUrl = base_url('plugins/lldpd/neighbours');
?>
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.ld-grid { display:grid; grid-template-columns:220px 1fr; gap:14px; }
@media (max-width: 820px) { .ld-grid { grid-template-columns:1fr; } }
.ld-meta dt { color:var(--text-muted); font-weight:600; }
.ld-meta dd { margin:0; color:var(--text-primary); font-family:var(--font-mono); font-size:.74rem; word-break:break-all; }
.ld-led { display:inline-block; width:9px; height:9px; border-radius:50%; background:#6b7280; box-shadow:0 0 6px rgba(0,0,0,.4); }
.ld-led.on { background:#22c55e; box-shadow:0 0 8px rgba(34,197,94,.65); }
.ld-led.off { background:#ef4444; box-shadow:0 0 8px rgba(239,68,68,.5); }
.ld-field-hint { font-size:.68rem; color:var(--text-muted); margin-top:2px; }
.ld-neighbours {
  margin-top:8px; padding:10px 12px; border-radius:8px; overflow:auto; max-height:420px;
  background:rgba(0,0,0,.28); border:1px solid var(--border-subtle, rgba(255,255,255,.08));
  color:var(--text-primary); font-family:var(--font-mono); font-size:.72rem; line-height:1.45; white-space:pre;
}
.ld-ntable-wrap { margin-top:10px; overflow-x:auto; }
.ld-ntable { width:100%; border-collapse:collapse; font-size:.74rem; }
.ld-ntable th, .ld-ntable td { text-align:left; padding:7px 10px; vertical-align:top; border-bottom:1px solid var(--border-subtle, rgba(255,255,255,.08)); }
.ld-ntable th {
  color:var(--text-muted); font-weight:600; white-space:nowrap; cursor:pointer; user-select:none;
  position:sticky; top:0; background:var(--bg-panel, rgba(17,20,26,.96));
}
.ld-ntable th:hover, .ld-ntable th:focus-visible { color:var(--text-primary); outline:none; }
.ld-ntable th .ld-caret { opacity:.35; margin-left:4px; font-size:.68rem; }
.ld-ntable th[aria-sort="ascending"] .ld-caret,
.ld-ntable th[aria-sort="descending"] .ld-caret { opacity:1; }
.ld-ntable tbody tr:hover { background:rgba(255,255,255,.03); }
.ld-ntable td.ld-mono { font-family:var(--font-mono); color:var(--text-secondary); word-break:break-all; }
.ld-ntable td.ld-blank { color:var(--text-muted); }
.ld-view-toggle { background:none; border:none; padding:0; cursor:pointer; color:var(--text-muted); font-size:.72rem; text-decoration:underline; }
.ld-view-toggle:hover { color:var(--text-primary); }
</style>

<div class="page-header animate-in">
  <div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <h1 class="page-title">lldpd</h1>
      <span class="badge badge-success" style="font-size:0.7rem;">CLASS B</span>
      <span class="badge badge-muted" style="font-size:0.7rem;"><?= esc($plugin['id']) ?> · v<?= esc($plugin['version']) ?></span>
      <span id="ldStateBadge" class="badge badge-muted" style="font-size:0.7rem;">CHECKING…</span>
    </div>
    <p class="page-subtitle">
      Link Layer Discovery Protocol daemon. Advertises this appliance to, and discovers, directly-connected
      neighbours. Read-only on the wire; no changes to the forwarding path.
    </p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
    <button type="button" class="btn btn-ghost btn-sm" onclick="ldRefresh()">Refresh status</button>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:16px 18px; margin-bottom:16px;">
  <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
    <span class="ld-led" id="ldLed"></span>
    <div style="font-weight:700; font-size:0.92rem;">Daemon status</div>
    <span id="ldStatusNote" style="font-size:.74rem; color:var(--text-muted);"></span>
  </div>

  <div class="ld-grid">
    <dl class="ld-meta" style="display:grid; grid-template-columns:1fr; gap:3px;">
      <dt>lldpd service</dt><dd id="ldService">—</dd>
      <dt>Configuration drop-in</dt><dd id="ldDropin">—</dd>
      <dt>Daemon config file</dt><dd id="ldConfig">—</dd>
      <dt>Interfaces</dt><dd id="ldIfaces">—</dd>
      <dt>Extra protocols</dt><dd id="ldProtocols">—</dd>
    </dl>
    <div>
      <dl class="ld-meta" style="display:grid; grid-template-columns:170px 1fr; gap:3px 12px;">
        <dt>Applied</dt><dd id="ldApplied">—</dd>
      </dl>
      <div id="ldWarnings" style="margin-top:10px; font-size:.72rem; color:#f59e0b;"></div>
      <div id="ldSteps" style="margin-top:8px; font-size:.7rem; color:var(--text-muted); font-family:var(--font-mono);"></div>
    </div>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px; margin-bottom:16px;">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
    <div>
      <div style="font-weight:700; font-size:0.92rem;">Neighbours</div>
      <div style="font-size:.74rem; color:var(--text-muted);">
        What the daemon currently sees, parsed into one row per neighbour — click a column to sort. When the
        table cannot be built the listing is shown exactly as <code>lldpcli show neighbors</code> prints it.
      </div>
    </div>
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <button type="button" class="ld-view-toggle" id="ldNbToggle" onclick="ldToggleNeighboursView()">Show raw output</button>
      <button type="button" class="btn btn-primary btn-sm" onclick="ldNeighbours()">Refresh neighbours</button>
    </div>
  </div>
  <div id="ldNeighboursNote" style="margin-top:8px; font-size:.74rem; color:var(--text-muted);"></div>

  <div class="ld-ntable-wrap" id="ldNeighboursTableWrap">
    <table class="ld-ntable" id="ldNeighboursTable">
      <thead>
        <tr>
          <th data-key="interface" role="button" tabindex="0" aria-sort="ascending" onclick="ldNbSort('interface')" onkeydown="ldNbHeaderKey(event, 'interface')">Local port<span class="ld-caret">▲</span></th>
          <th data-key="chassis" role="button" tabindex="0" onclick="ldNbSort('chassis')" onkeydown="ldNbHeaderKey(event, 'chassis')">Remote chassis<span class="ld-caret">▲</span></th>
          <th data-key="port" role="button" tabindex="0" onclick="ldNbSort('port')" onkeydown="ldNbHeaderKey(event, 'port')">Remote port<span class="ld-caret">▲</span></th>
          <th data-key="mgmt_ip" role="button" tabindex="0" onclick="ldNbSort('mgmt_ip')" onkeydown="ldNbHeaderKey(event, 'mgmt_ip')">Management address<span class="ld-caret">▲</span></th>
          <th data-key="ttl" role="button" tabindex="0" onclick="ldNbSort('ttl')" onkeydown="ldNbHeaderKey(event, 'ttl')">TTL<span class="ld-caret">▲</span></th>
        </tr>
      </thead>
      <tbody id="ldNeighboursBody"></tbody>
    </table>
  </div>

  <div id="ldRawWrap" hidden>
    <pre class="ld-neighbours" id="ldNeighbours">— not read yet —</pre>
  </div>
  <div id="ldNeighboursWarnings" style="margin-top:8px; font-size:.72rem; color:#f59e0b;"></div>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px; margin-bottom:16px;">
  <div style="font-weight:700; font-size:0.92rem; margin-bottom:2px;">Settings</div>
  <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:12px;">
    Most of lldpd's options are command line, so applying these writes a systemd drop-in over the package's own
    <code>lldpd.service</code>; the transmit timing and an optional hostname override go in
    <code>/etc/lldpd.conf</code>, which the daemon processes at start. The unit name stays the package's, so the
    generic plugin status and control still reach the same service.
  </div>
  <form id="ldForm" onsubmit="return ldApply(event)">
    <div class="repo-grid">
      <div class="repo-field">
        <label for="ldInterface">Interfaces</label>
        <input type="text" id="ldInterface" name="interfaces" placeholder="eth0, eth1" spellcheck="false">
        <div class="repo-field-hint ld-field-hint">
          Comma-separated, with lldpd's globs and <code>!</code> rules (e.g. <code>eth*,!eth1</code>). Leave empty for
          every physical interface.
        </div>
      </div>
      <div class="repo-field">
        <label for="ldMgmt">Management addresses</label>
        <input type="text" id="ldMgmt" name="management_addresses" placeholder="(first IPv4 and IPv6)" spellcheck="false">
        <div class="ld-field-hint">Advertised management address pattern, e.g. <code>eth0</code> or <code>!*:*</code> to drop IPv6.</div>
      </div>
      <div class="repo-field">
        <label for="ldSystemName">System name</label>
        <input type="text" id="ldSystemName" name="system_name" placeholder="(resolved hostname)" spellcheck="false">
        <div class="ld-field-hint">Overrides the advertised system name; empty uses the appliance's own hostname.</div>
      </div>
      <div class="repo-field">
        <label for="ldDescription">System description</label>
        <input type="text" id="ldDescription" name="system_description" placeholder="(kernel name, version, architecture)" spellcheck="false">
        <div class="ld-field-hint">Overrides the chassis description (<code>-S</code>).</div>
      </div>
      <div class="repo-field">
        <label for="ldPlatform">CDP platform</label>
        <input type="text" id="ldPlatform" name="platform" placeholder="(kernel name)" spellcheck="false">
        <div class="ld-field-hint">Overrides the platform CDP advertises (<code>-P</code>).</div>
      </div>
      <div class="repo-field">
        <label for="ldTxInterval">Transmit interval (seconds)</label>
        <input type="number" id="ldTxInterval" name="tx_interval" min="1" max="3600" value="30">
        <div class="ld-field-hint">Delay between LLDP advertisements. Default 30.</div>
      </div>
      <div class="repo-field">
        <label for="ldTxHold">Transmit hold</label>
        <input type="number" id="ldTxHold" name="tx_hold" min="1" max="100" value="4">
        <div class="ld-field-hint">Multiplied by the interval to give the TTL. Default 4.</div>
      </div>
      <div class="repo-field">
        <label for="ldReceiveOnly" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="ldReceiveOnly" name="receive_only">
          Receive-only (do not advertise)
        </label>
        <div class="ld-field-hint">Passes <code>-r</code>: listen for neighbours, send nothing.</div>
      </div>
      <div class="repo-field">
        <label for="ldCdp" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="ldCdp" name="cdp">
          Also speak CDP (Cisco)
        </label>
        <div class="ld-field-hint">Passes <code>-c</code>.</div>
      </div>
      <div class="repo-field">
        <label for="ldFdp" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="ldFdp" name="fdp">
          Also speak FDP (Foundry)
        </label>
        <div class="ld-field-hint">Passes <code>-f</code>.</div>
      </div>
      <div class="repo-field">
        <label for="ldSonmp" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="ldSonmp" name="sonmp">
          Also speak SONMP (Nortel)
        </label>
        <div class="ld-field-hint">Passes <code>-s</code>.</div>
      </div>
      <div class="repo-field">
        <label for="ldEdp" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="ldEdp" name="edp">
          Also speak EDP (Extreme)
        </label>
        <div class="ld-field-hint">Passes <code>-e</code>.</div>
      </div>
      <div class="repo-field">
        <label for="ldSnmp" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="ldSnmp" name="snmp_subagent">
          Enable the SNMP subagent
        </label>
        <div class="ld-field-hint">Passes <code>-x</code>; exposes the neighbour table over SNMP/AgentX.</div>
      </div>
      <div class="repo-field">
        <label for="ldSuppressKernel" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="ldSuppressKernel" name="suppress_kernel_version">
          Hide kernel version and machine
        </label>
        <div class="ld-field-hint">Passes <code>-k</code>; the software version is advertised as “Unknown”.</div>
      </div>
      <div class="repo-field">
        <label for="ldEnabled" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="ldEnabled" name="enabled" checked>
          lldpd enabled
        </label>
        <div class="ld-field-hint">Unchecking removes the drop-in and the config, and stops the service.</div>
      </div>
    </div>
    <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary btn-sm">Apply settings</button>
    </div>
    <div id="ldFormNote" style="margin-top:10px; font-size:.74rem;"></div>
  </form>
</div>

<script>
const LD_STATUS_URL     = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
const LD_APPLY_URL      = <?= json_encode($applyUrl, JSON_UNESCAPED_SLASHES) ?>;
const LD_NEIGHBOURS_URL = <?= json_encode($neighboursUrl, JSON_UNESCAPED_SLASHES) ?>;
let LD_CSRF = <?= json_encode(csrf_hash()) ?>;

function ldLed(el, on) {
  el.classList.remove('on', 'off');
  el.classList.add(on ? 'on' : 'off');
}

function ldSet(id, value, fallback) {
  document.getElementById(id).textContent = (value === undefined || value === null || value === '') ? (fallback || '—') : value;
}

function ldProtocols(data) {
  const names = [];
  if (data.cdp) names.push('CDP');
  if (data.fdp) names.push('FDP');
  if (data.sonmp) names.push('SONMP');
  if (data.edp) names.push('EDP');
  return names.length ? names.join(', ') : 'LLDP only';
}

function ldRender(result) {
  const data = (result && result.data) || {};
  const badge = document.getElementById('ldStateBadge');
  const running = data.service_state === 'running';

  badge.textContent = running ? (data.enabled === false ? 'STOPPED' : 'RUNNING') : (data.dropin_present ? 'STOPPED' : 'NOT CONFIGURED');
  badge.className = 'badge ' + (running && data.enabled !== false ? 'badge-success' : (data.dropin_present ? 'badge-warning' : 'badge-muted'));
  ldLed(document.getElementById('ldLed'), running && data.enabled !== false);

  ldSet('ldService', data.service_state, '—');
  ldSet('ldDropin', data.dropin_present ? 'present' : 'absent', 'absent');
  ldSet('ldConfig', data.config_present ? 'present' : 'absent', 'absent');
  ldSet('ldIfaces', data.interfaces, 'all physical interfaces');
  ldSet('ldProtocols', ldProtocols(data), 'LLDP only');
  ldSet('ldApplied', data.applied_at, data.dropin_present ? '—' : 'never');

  // Pre-fill the form from the applied settings so a reload shows what is live.
  if (data.interfaces !== undefined) document.getElementById('ldInterface').value = data.interfaces || '';
  if (data.management_addresses !== undefined) document.getElementById('ldMgmt').value = data.management_addresses || '';
  if (data.system_name !== undefined) document.getElementById('ldSystemName').value = data.system_name || '';
  if (data.system_description !== undefined) document.getElementById('ldDescription').value = data.system_description || '';
  if (data.platform !== undefined) document.getElementById('ldPlatform').value = data.platform || '';
  if (data.tx_interval) document.getElementById('ldTxInterval').value = data.tx_interval;
  if (data.tx_hold) document.getElementById('ldTxHold').value = data.tx_hold;
  if (data.receive_only !== undefined) document.getElementById('ldReceiveOnly').checked = data.receive_only === true;
  if (data.cdp !== undefined) document.getElementById('ldCdp').checked = data.cdp === true;
  if (data.fdp !== undefined) document.getElementById('ldFdp').checked = data.fdp === true;
  if (data.sonmp !== undefined) document.getElementById('ldSonmp').checked = data.sonmp === true;
  if (data.edp !== undefined) document.getElementById('ldEdp').checked = data.edp === true;
  if (data.snmp_subagent !== undefined) document.getElementById('ldSnmp').checked = data.snmp_subagent === true;
  if (data.suppress_kernel_version !== undefined) document.getElementById('ldSuppressKernel').checked = data.suppress_kernel_version === true;
  if (data.enabled !== undefined) document.getElementById('ldEnabled').checked = data.enabled === true;

  const warnings = (result && result.warnings) || [];
  document.getElementById('ldWarnings').innerHTML = warnings.map(w => '⚠ ' + w).join('<br>');
  const steps = (result && result.steps) || [];
  document.getElementById('ldSteps').innerHTML = steps.map(s => '· ' + s).join('<br>');
}

async function ldCall(url, options) {
  const response = await MicronScheduler.fetch(url, options);
  return response.json();
}

async function ldRefresh() {
  const note = document.getElementById('ldStatusNote');
  note.textContent = 'reading…';
  try {
    const body = await ldCall(LD_STATUS_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (body.csrf) LD_CSRF = body.csrf;
    ldRender(body.result);
    note.textContent = body.ok ? '' : (body.message || 'the daemon rejected the request');
  } catch (e) {
    note.textContent = 'status unavailable';
  }
}

// --- Neighbour table -------------------------------------------------------
// The daemon returns one structured row per neighbour (`format=json`); this
// renders them as a sortable table. LLDP data arrives from the network, so every
// cell is set as text — never markup. When the structured rows cannot be built
// (parse error, no neighbours, or the daemon cannot answer) the page falls back
// to the listing exactly as `lldpcli show neighbors` prints it.
const LD_NB_KEYS = ['interface', 'chassis', 'port', 'mgmt_ip', 'ttl'];
let LD_NB_ROWS = [];
let LD_NB_SORT = { key: 'interface', dir: 'asc' };
let LD_NB_VIEW = 'table';
let LD_NB_RAW_LOADED = false;

function ldNbFetch(format) {
  const sep = LD_NEIGHBOURS_URL.indexOf('?') === -1 ? '?' : '&';
  return ldCall(LD_NEIGHBOURS_URL + sep + 'format=' + encodeURIComponent(format),
                { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
}

function ldNbWarnings(list) {
  const box = document.getElementById('ldNeighboursWarnings');
  box.textContent = '';
  (list || []).filter(Boolean).forEach(function (w) {
    const line = document.createElement('div');
    line.textContent = '⚠ ' + w;
    box.appendChild(line);
  });
}

function ldNbCell(tag, value, mono) {
  const cell = document.createElement(tag);
  if (mono) cell.className = 'ld-mono';
  const blank = value === null || value === undefined || value === '';
  if (blank) cell.className += ' ld-blank';
  cell.textContent = blank ? '—' : String(value);
  return cell;
}

// Kept in one place so sorting and rendering cannot disagree about a row.
function ldNbSorted() {
  const rows = LD_NB_ROWS.slice();
  const key = LD_NB_SORT.key;
  const dir = LD_NB_SORT.dir === 'desc' ? -1 : 1;
  return rows.sort(function (a, b) {
    const av = a[key] === null || a[key] === undefined ? '' : String(a[key]);
    const bv = b[key] === null || b[key] === undefined ? '' : String(b[key]);
    if (av === '' && bv !== '') return 1;   // blanks sort last, either direction
    if (bv === '' && av !== '') return -1;
    // numeric:true sorts a TTL as a number and a port name naturally.
    return dir * av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' });
  });
}

function ldNbRenderTable() {
  const body = document.getElementById('ldNeighboursBody');
  body.textContent = '';
  ldNbSorted().forEach(function (row) {
    const tr = document.createElement('tr');
    const title = [];
    if (row.chassis_id) title.push('Chassis ID ' + row.chassis_id);
    if (row.chassis_descr) title.push(row.chassis_descr);
    if (row.port_descr) title.push('Port ' + row.port_descr);
    if (row.age) title.push('Seen ' + row.age);
    if (row.via) title.push(row.via);
    if (title.length) tr.title = title.join(' — ');
    tr.appendChild(ldNbCell('td', row.interface, true));
    tr.appendChild(ldNbCell('td', row.chassis, false));
    tr.appendChild(ldNbCell('td', row.port, true));
    tr.appendChild(ldNbCell('td', row.mgmt_ip, true));
    tr.appendChild(ldNbCell('td', row.ttl, true));
    body.appendChild(tr);
  });

  document.querySelectorAll('#ldNeighboursTable th').forEach(function (th) {
    const sorted = th.getAttribute('data-key') === LD_NB_SORT.key;
    th.setAttribute('aria-sort', sorted ? (LD_NB_SORT.dir === 'desc' ? 'descending' : 'ascending') : 'none');
    const caret = th.querySelector('.ld-caret');
    if (caret) caret.textContent = sorted && LD_NB_SORT.dir === 'desc' ? '▼' : '▲';
  });
}

function ldNbSort(key) {
  if (LD_NB_KEYS.indexOf(key) === -1) return;
  if (LD_NB_SORT.key === key) {
    LD_NB_SORT.dir = LD_NB_SORT.dir === 'asc' ? 'desc' : 'asc';
  } else {
    LD_NB_SORT = { key: key, dir: 'asc' };
  }
  ldNbRenderTable();
}

function ldNbHeaderKey(event, key) {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault();
    ldNbSort(key);
  }
}

function ldNbShowView(view) {
  LD_NB_VIEW = view;
  document.getElementById('ldNeighboursTableWrap').hidden = view !== 'table';
  document.getElementById('ldRawWrap').hidden = view !== 'raw';
  document.getElementById('ldNbToggle').textContent = view === 'raw' ? 'Show table' : 'Show raw output';
}

async function ldNbLoadRaw(note) {
  try {
    const body = await ldNbFetch('text');
    if (body.csrf) LD_CSRF = body.csrf;
    const data = (body.result && body.result.data) || {};
    const text = data.neighbours || '';
    document.getElementById('ldNeighbours').textContent =
      (body.ok && text.trim()) ? text : '— no LLDP neighbours are currently visible —';
    LD_NB_RAW_LOADED = true;
    if (!body.ok) note.textContent = body.message || 'the daemon rejected the request';
    return body;
  } catch (e) {
    document.getElementById('ldNeighbours').textContent = '— unavailable —';
    LD_NB_RAW_LOADED = true;
    note.textContent = 'the raw listing is unavailable';
    return null;
  }
}

async function ldToggleNeighboursView() {
  const note = document.getElementById('ldNeighboursNote');
  if (LD_NB_VIEW === 'table') {
    ldNbShowView('raw');
    if (!LD_NB_RAW_LOADED) await ldNbLoadRaw(note);
  } else if (LD_NB_ROWS.length) {
    ldNbShowView('table');
  } else {
    // No structured rows to go back to — read the table again.
    await ldNeighbours();
  }
}

async function ldNeighbours() {
  const note = document.getElementById('ldNeighboursNote');
  note.textContent = 'reading…';
  ldNbWarnings([]);

  let body = null;
  try {
    body = await ldNbFetch('json');
  } catch (e) {
    body = null;
  }
  if (body && body.csrf) LD_CSRF = body.csrf;

  const warnings = (body && body.result && body.result.warnings) || [];
  const rows = (body && body.ok && body.result && body.result.data && body.result.data.ports) || [];

  if (Array.isArray(rows) && rows.length) {
    LD_NB_ROWS = rows.filter(function (r) { return r && typeof r === 'object'; });
    ldNbRenderTable();
    ldNbShowView('table');
    note.textContent = LD_NB_ROWS.length + (LD_NB_ROWS.length === 1 ? ' neighbour' : ' neighbours') + ' discovered.';
    ldNbWarnings(warnings);
    return;
  }

  // Nothing structured to show: fall back to the raw listing.
  ldNbShowView('raw');
  const raw = await ldNbLoadRaw(note);
  const rawWarnings = (raw && raw.result && raw.result.warnings) || [];
  if (!raw) {
    warnings.push('the neighbour table could not be read');
  } else if (raw.ok) {
    // The raw listing worked, so name the reason the table is not shown.
    note.textContent = (body && !body.ok && body.message)
      ? body.message + ' — showing the raw listing.'
      : 'No neighbours were parsed into a table — showing the raw listing.';
  }
  ldNbWarnings(warnings.concat(rawWarnings));
}

async function ldApply(event) {
  event.preventDefault();
  const note = document.getElementById('ldStatusNote');
  const formNote = document.getElementById('ldFormNote');
  note.textContent = 'applying…';
  formNote.textContent = '';
  formNote.style.color = 'var(--text-muted)';

  const body = await ldCall(LD_APPLY_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': LD_CSRF },
    body: JSON.stringify({
      enabled: document.getElementById('ldEnabled').checked,
      interfaces: document.getElementById('ldInterface').value.trim(),
      management_addresses: document.getElementById('ldMgmt').value.trim(),
      system_name: document.getElementById('ldSystemName').value.trim(),
      system_description: document.getElementById('ldDescription').value.trim(),
      platform: document.getElementById('ldPlatform').value.trim(),
      tx_interval: parseInt(document.getElementById('ldTxInterval').value, 10),
      tx_hold: parseInt(document.getElementById('ldTxHold').value, 10),
      receive_only: document.getElementById('ldReceiveOnly').checked,
      cdp: document.getElementById('ldCdp').checked,
      fdp: document.getElementById('ldFdp').checked,
      sonmp: document.getElementById('ldSonmp').checked,
      edp: document.getElementById('ldEdp').checked,
      snmp_subagent: document.getElementById('ldSnmp').checked,
      suppress_kernel_version: document.getElementById('ldSuppressKernel').checked,
    }),
  });
  if (body.csrf) LD_CSRF = body.csrf;

  formNote.textContent = body.ok ? (body.message || 'applied.') : ('failed: ' + (body.message || 'unknown error'));
  formNote.style.color = body.ok ? '#22c55e' : '#ef4444';
  note.textContent = body.ok ? '' : 'apply failed';
  if (body.result) ldRender(body.result);
  return false;
}

ldRefresh();
ldNeighbours();
</script>

<?= $this->endSection() ?>
