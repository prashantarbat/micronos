<?php
/**
 * lldpd page — the Class B plugin's own UI surface.
 *
 * Rendered by `Plugins::show()` in place of the generic metadata page, so an
 * installed plugin's nav entry ("LLDP Neighbours") lands on something usable.
 * It carries both halves: the settings form and the neighbour table.
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
.ld-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; }
.ld-meta dt { color:var(--text-muted); font-size:0.7rem; text-transform:uppercase; font-weight:600; letter-spacing:0.04em; margin-bottom:2px; }
.ld-meta dd { margin:0; color:var(--text-primary); font-family:var(--font-mono); font-size:.85rem; font-weight:600; word-break:break-all; }
.ld-led { display:inline-block; width:10px; height:10px; border-radius:50%; background:#6b7280; box-shadow:0 0 6px rgba(0,0,0,.4); }
.ld-led.on { background:#22c55e; box-shadow:0 0 8px rgba(34,197,94,.65); }
.ld-led.off { background:#ef4444; box-shadow:0 0 8px rgba(239,68,68,.5); }
.ld-field-hint { font-size:.7rem; color:var(--text-muted); margin-top:3px; }
.ld-neighbours {
  margin-top:8px; padding:12px 14px; border-radius:8px; overflow:auto; max-height:420px;
  background:rgba(0,0,0,.35); border:1px solid var(--border-subtle, rgba(255,255,255,.08));
  color:var(--text-primary); font-family:var(--font-mono); font-size:.74rem; line-height:1.45; white-space:pre;
}
.ld-ntable-wrap { margin-top:10px; overflow-x:auto; border-radius:8px; border:1px solid var(--border-color, rgba(255,255,255,.08)); }
.ld-ntable { width:100%; border-collapse:collapse; font-size:.8rem; }
.ld-ntable th, .ld-ntable td { text-align:left; padding:10px 14px; vertical-align:middle; border-bottom:1px solid var(--border-subtle, rgba(255,255,255,.06)); }
.ld-ntable th {
  color:var(--text-muted); font-weight:600; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.03em;
  white-space:nowrap; cursor:pointer; user-select:none; background:rgba(255,255,255,0.03);
}
.ld-ntable th:hover, .ld-ntable th:focus-visible { color:var(--text-primary); outline:none; }
.ld-ntable th .ld-caret { opacity:.4; margin-left:4px; font-size:.7rem; }
.ld-ntable th[aria-sort="ascending"] .ld-caret,
.ld-ntable th[aria-sort="descending"] .ld-caret { opacity:1; color:var(--color-cyan, #06b6d4); }
.ld-ntable tbody tr:hover { background:rgba(255,255,255,.03); }
.ld-ntable td.ld-mono { font-family:var(--font-mono); color:var(--text-primary); font-weight:600; }
.ld-ntable td.ld-blank { color:var(--text-muted); }
.ld-view-toggle { background:none; border:none; padding:0; cursor:pointer; color:var(--color-cyan, #06b6d4); font-size:.76rem; text-decoration:underline; }
.ld-view-toggle:hover { opacity:0.8; }
details.ld-advanced { border:1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius:8px; padding:12px 14px; background:rgba(0,0,0,0.12); margin-top:16px; }
details.ld-advanced > summary { cursor:pointer; font-weight:600; font-size:0.82rem; color:var(--text-muted); display:flex; align-items:center; gap:8px; list-style:none; user-select:none; }
details.ld-advanced > summary::-webkit-details-marker { display:none; }
details.ld-advanced > summary:hover { color:var(--text-primary); }
details.ld-advanced[open] > summary { margin-bottom:14px; color:var(--text-primary); }
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
        <span style="color:var(--text-primary); font-weight:600;">LLDP Neighbours</span>
      </div>
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">lldpd</h1>
        <span class="badge badge-success" style="font-size:0.7rem;">CLASS B</span>
        <span class="badge badge-muted" style="font-size:0.7rem;"><?= esc($plugin['id']) ?> · v<?= esc($plugin['version']) ?></span>
        <span id="ldStateBadge" class="badge badge-muted" style="font-size:0.7rem;">CHECKING…</span>
      </div>
      <p class="page-subtitle" style="margin-top:6px;">
        Link Layer Discovery Protocol daemon. Advertises appliance identity and detects directly-connected network switches, routers, and APs.
      </p>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <button type="button" class="btn btn-primary btn-sm" onclick="ldNeighbours()" style="display:inline-flex; align-items:center; gap:6px;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Refresh Neighbours
      </button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="ldRefresh()">Refresh Status</button>
      <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
      <?= \App\Libraries\ServiceStatus::widget('lldpd') ?>
    </div>
  </div>
</div>

<!-- ── Discovered Neighbours Table (Main Feature Front & Center) ── -->
<div class="card card-glass animate-in animate-in-delay-1" style="padding:20px; margin-bottom:20px;">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
    <div>
      <div style="display:flex; align-items:center; gap:8px;">
        <h2 style="font-weight:700; font-size:1.05rem; margin:0; color:var(--text-primary);">Neighbours</h2>
        <span id="ldNeighbourBadge" class="badge badge-subtle" style="font-size:0.75rem;">LIVE DISCOVERY</span>
      </div>
      <div style="font-size:.76rem; color:var(--text-muted); margin-top:3px;">
        Directly connected switches and ports discovered by the link-layer protocol engine. Click any column header to sort.
      </div>
    </div>
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <button type="button" class="ld-view-toggle" id="ldNbToggle" onclick="ldToggleNeighboursView()">Show raw output</button>
      <button type="button" class="btn btn-sm btn-ghost" onclick="ldNeighbours()" style="border:1px solid rgba(255,255,255,0.12);">
        Refresh
      </button>
    </div>
  </div>

  <div id="ldNeighboursNote" style="margin-bottom:8px; font-size:.75rem; color:var(--text-muted); font-weight:600;"></div>

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
      <tbody id="ldNeighboursBody">
        <tr>
          <td colspan="5" style="text-align:center; padding:24px; color:var(--text-muted);">Discovering connected switch ports…</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div id="ldRawWrap" hidden>
    <pre class="ld-neighbours" id="ldNeighbours">— not read yet —</pre>
  </div>
  <div id="ldNeighboursWarnings" style="margin-top:8px; font-size:.72rem; color:#f59e0b;"></div>
</div>

<!-- ── Daemon Status & Network Summary ── -->
<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 20px; margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
    <div style="display:flex; align-items:center; gap:10px;">
      <span class="ld-led" id="ldLed"></span>
      <div style="font-weight:700; font-size:0.92rem; color:var(--text-primary);">Daemon Status</div>
      <span id="ldStatusNote" style="font-size:.74rem; color:var(--text-muted);"></span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted);" id="ldApplied">Last applied: —</div>
  </div>

  <div class="ld-grid">
    <div style="padding:10px 12px; background:rgba(255,255,255,0.02); border-radius:6px; border:1px solid rgba(255,255,255,0.04);">
      <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Service Unit</div>
      <div id="ldService" style="font-size:1.15rem; font-weight:700; color:var(--text-primary); margin-top:2px;">—</div>
      <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">lldpd.service</div>
    </div>

    <div style="padding:10px 12px; background:rgba(255,255,255,0.02); border-radius:6px; border:1px solid rgba(255,255,255,0.04);">
      <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Monitored Interfaces</div>
      <div id="ldIfaces" style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-top:2px; font-family:var(--font-mono);">—</div>
      <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">Listening ports</div>
    </div>

    <div style="padding:10px 12px; background:rgba(255,255,255,0.02); border-radius:6px; border:1px solid rgba(255,255,255,0.04);">
      <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Extra Protocols</div>
      <div id="ldProtocols" style="font-size:1.1rem; font-weight:700; color:var(--color-cyan, #06b6d4); margin-top:2px;">—</div>
      <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">CDP / FDP / SONMP / EDP</div>
    </div>

    <div style="padding:10px 12px; background:rgba(255,255,255,0.02); border-radius:6px; border:1px solid rgba(255,255,255,0.04);">
      <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Configuration File</div>
      <div id="ldDropin" style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-top:2px;">—</div>
      <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;" id="ldConfig">drop-in active</div>
    </div>
  </div>

  <div id="ldWarnings" style="margin-top:10px; font-size:.72rem; color:#f59e0b;"></div>
  <div id="ldSteps" style="margin-top:6px; font-size:.7rem; color:var(--text-muted); font-family:var(--font-mono);"></div>
</div>

<!-- ── Streamlined Settings Card ── -->
<div class="card card-glass animate-in animate-in-delay-3" style="padding:20px; margin-bottom:20px;">
  <div style="margin-bottom:16px;">
    <h2 style="font-weight:700; font-size:1rem; margin:0; color:var(--text-primary);">Settings</h2>
    <div style="font-size:.76rem; color:var(--text-muted); margin-top:2px;">
      Configure interface listening scope and discovery protocol behavior.
    </div>
  </div>

  <form id="ldForm" onsubmit="return ldApply(event)">
    <!-- Primary Options: The 3-4 essential toggles -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px; margin-bottom:14px;">
      <div style="padding:12px 14px; background:rgba(255,255,255,0.02); border-radius:8px; border:1px solid rgba(255,255,255,0.06);">
        <label for="ldEnabled" style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:600; color:var(--text-primary);">
          <input type="checkbox" id="ldEnabled" name="enabled" checked style="width:18px; height:18px;">
          <span>lldpd enabled</span>
        </label>
        <div class="ld-field-hint" style="margin-left:28px;">Run background daemon to discover and announce network links.</div>
      </div>

      <div>
        <label for="ldInterface" style="font-weight:600; font-size:0.82rem;">Interfaces</label>
        <input type="text" id="ldInterface" name="interfaces" placeholder="e.g. eth0, eth1 (leave empty for all)" spellcheck="false" class="form-control" style="margin-top:4px;">
        <div class="ld-field-hint">Comma-separated, or leave empty for all physical interfaces.</div>
      </div>

      <div style="display:flex; flex-direction:column; gap:8px; justify-content:center;">
        <label for="ldCdp" style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem;">
          <input type="checkbox" id="ldCdp" name="cdp">
          <span>Also speak CDP (Cisco)</span>
        </label>
        <label for="ldReceiveOnly" style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem;">
          <input type="checkbox" id="ldReceiveOnly" name="receive_only">
          <span>Receive-only (do not advertise)</span>
        </label>
      </div>
    </div>

    <!-- Advanced Collapsible Options (Hidden by default to keep the UI clean) -->
    <details class="ld-advanced">
      <summary>
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
        <span>Advanced Options (Timing, Identifiers, Foundry/Nortel &amp; SNMP)</span>
      </summary>

      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:14px; margin-top:12px;">
        <div>
          <label for="ldMgmt" style="font-weight:600; font-size:0.8rem;">Management addresses</label>
          <input type="text" id="ldMgmt" name="management_addresses" placeholder="(first IPv4 and IPv6)" spellcheck="false" class="form-control" style="margin-top:4px;">
          <div class="ld-field-hint">Pattern for management IP advertised to peers (e.g. <code>eth0</code>).</div>
        </div>

        <div>
          <label for="ldSystemName" style="font-weight:600; font-size:0.8rem;">System name</label>
          <input type="text" id="ldSystemName" name="system_name" placeholder="(resolved hostname)" spellcheck="false" class="form-control" style="margin-top:4px;">
          <div class="ld-field-hint">Overrides advertised hostname. Empty uses appliance hostname.</div>
        </div>

        <div>
          <label for="ldDescription" style="font-weight:600; font-size:0.8rem;">System description</label>
          <input type="text" id="ldDescription" name="system_description" placeholder="(kernel name, version, architecture)" spellcheck="false" class="form-control" style="margin-top:4px;">
          <div class="ld-field-hint">Overrides chassis description string (<code>-S</code>).</div>
        </div>

        <div>
          <label for="ldPlatform" style="font-weight:600; font-size:0.8rem;">CDP platform</label>
          <input type="text" id="ldPlatform" name="platform" placeholder="(kernel name)" spellcheck="false" class="form-control" style="margin-top:4px;">
          <div class="ld-field-hint">Overrides the platform string CDP advertises (<code>-P</code>).</div>
        </div>

        <div>
          <label for="ldTxInterval" style="font-weight:600; font-size:0.8rem;">Transmit interval (seconds)</label>
          <input type="number" id="ldTxInterval" name="tx_interval" min="1" max="3600" value="30" class="form-control" style="margin-top:4px;">
          <div class="ld-field-hint">Delay between LLDP advertisements (Default 30s).</div>
        </div>

        <div>
          <label for="ldTxHold" style="font-weight:600; font-size:0.8rem;">Transmit hold</label>
          <input type="number" id="ldTxHold" name="tx_hold" min="1" max="100" value="4" class="form-control" style="margin-top:4px;">
          <div class="ld-field-hint">Multiplier to determine neighbour TTL (Default 4).</div>
        </div>

        <div style="display:flex; flex-direction:column; gap:8px;">
          <label for="ldFdp" style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem;">
            <input type="checkbox" id="ldFdp" name="fdp">
            <span>Also speak FDP (Foundry)</span>
          </label>
          <label for="ldSonmp" style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem;">
            <input type="checkbox" id="ldSonmp" name="sonmp">
            <span>Also speak SONMP (Nortel)</span>
          </label>
          <label for="ldEdp" style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem;">
            <input type="checkbox" id="ldEdp" name="edp">
            <span>Also speak EDP (Extreme)</span>
          </label>
        </div>

        <div style="display:flex; flex-direction:column; gap:8px;">
          <label for="ldSnmp" style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem;">
            <input type="checkbox" id="ldSnmp" name="snmp_subagent">
            <span>Enable the SNMP subagent</span>
          </label>
          <label for="ldSuppressKernel" style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem;">
            <input type="checkbox" id="ldSuppressKernel" name="suppress_kernel_version">
            <span>Hide kernel version and machine</span>
          </label>
        </div>
      </div>
    </details>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; flex-wrap:wrap; gap:12px;">
      <button type="submit" class="btn btn-primary">
        Apply settings
      </button>
      <div id="ldFormNote" style="font-size:.82rem; font-weight:600;"></div>
    </div>
  </form>
</div>

<script>
const LD_STATUS_URL     = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
const LD_APPLY_URL      = <?= json_encode($applyUrl, JSON_UNESCAPED_SLASHES) ?>;
const LD_NEIGHBOURS_URL = <?= json_encode($neighboursUrl, JSON_UNESCAPED_SLASHES) ?>;
let LD_CSRF = <?= json_encode(csrf_hash()) ?>;

function ldLed(el, on) {
  if (!el) return;
  el.classList.remove('on', 'off');
  el.classList.add(on ? 'on' : 'off');
}

function ldSet(id, value, fallback) {
  const el = document.getElementById(id);
  if (el) el.textContent = (value === undefined || value === null || value === '') ? (fallback || '—') : value;
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

  if (badge) {
    badge.textContent = running ? (data.enabled === false ? 'STOPPED' : 'RUNNING') : (data.dropin_present ? 'STOPPED' : 'NOT CONFIGURED');
    badge.className = 'badge ' + (running && data.enabled !== false ? 'badge-success' : (data.dropin_present ? 'badge-warning' : 'badge-muted'));
  }
  ldLed(document.getElementById('ldLed'), running && data.enabled !== false);

  ldSet('ldService', data.service_state ? data.service_state.toUpperCase() : 'STOPPED', 'STOPPED');
  ldSet('ldDropin', data.dropin_present ? 'Active' : 'Not generated', 'Absent');
  ldSet('ldConfig', data.config_present ? 'Configured' : 'Default', 'Default');
  ldSet('ldIfaces', data.interfaces || 'All physical interfaces', 'All physical interfaces');
  ldSet('ldProtocols', ldProtocols(data), 'LLDP only');
  ldSet('ldApplied', data.applied_at ? 'Applied: ' + data.applied_at : (data.dropin_present ? '—' : 'Never applied'));

  // Pre-fill the form from applied settings
  if (data.interfaces !== undefined && document.getElementById('ldInterface')) document.getElementById('ldInterface').value = data.interfaces || '';
  if (data.management_addresses !== undefined && document.getElementById('ldMgmt')) document.getElementById('ldMgmt').value = data.management_addresses || '';
  if (data.system_name !== undefined && document.getElementById('ldSystemName')) document.getElementById('ldSystemName').value = data.system_name || '';
  if (data.system_description !== undefined && document.getElementById('ldDescription')) document.getElementById('ldDescription').value = data.system_description || '';
  if (data.platform !== undefined && document.getElementById('ldPlatform')) document.getElementById('ldPlatform').value = data.platform || '';
  if (data.tx_interval && document.getElementById('ldTxInterval')) document.getElementById('ldTxInterval').value = data.tx_interval;
  if (data.tx_hold && document.getElementById('ldTxHold')) document.getElementById('ldTxHold').value = data.tx_hold;
  if (data.receive_only !== undefined && document.getElementById('ldReceiveOnly')) document.getElementById('ldReceiveOnly').checked = data.receive_only === true;
  if (data.cdp !== undefined && document.getElementById('ldCdp')) document.getElementById('ldCdp').checked = data.cdp === true;
  if (data.fdp !== undefined && document.getElementById('ldFdp')) document.getElementById('ldFdp').checked = data.fdp === true;
  if (data.sonmp !== undefined && document.getElementById('ldSonmp')) document.getElementById('ldSonmp').checked = data.sonmp === true;
  if (data.edp !== undefined && document.getElementById('ldEdp')) document.getElementById('ldEdp').checked = data.edp === true;
  if (data.snmp_subagent !== undefined && document.getElementById('ldSnmp')) document.getElementById('ldSnmp').checked = data.snmp_subagent === true;
  if (data.suppress_kernel_version !== undefined && document.getElementById('ldSuppressKernel')) document.getElementById('ldSuppressKernel').checked = data.suppress_kernel_version === true;
  if (data.enabled !== undefined && document.getElementById('ldEnabled')) document.getElementById('ldEnabled').checked = data.enabled === true;

  const warnings = (result && result.warnings) || [];
  const warnEl = document.getElementById('ldWarnings');
  if (warnEl) warnEl.innerHTML = warnings.map(w => '⚠ ' + w).join('<br>');
  const steps = (result && result.steps) || [];
  const stepsEl = document.getElementById('ldSteps');
  if (stepsEl) stepsEl.innerHTML = steps.map(s => '· ' + s).join('<br>');
}

async function ldCall(url, options) {
  const response = await MicronScheduler.fetch(url, options);
  return response.json();
}

async function ldRefresh() {
  const note = document.getElementById('ldStatusNote');
  if (note) note.textContent = 'reading…';
  try {
    const body = await ldCall(LD_STATUS_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (body.csrf) LD_CSRF = body.csrf;
    ldRender(body.result);
    if (note) note.textContent = body.ok ? '' : (body.message || 'the daemon rejected the request');
  } catch (e) {
    if (note) note.textContent = 'status unavailable';
  }
}

// --- Neighbour table -------------------------------------------------------
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
  if (!box) return;
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

function ldNbSorted() {
  const rows = LD_NB_ROWS.slice();
  const key = LD_NB_SORT.key;
  const dir = LD_NB_SORT.dir === 'desc' ? -1 : 1;
  return rows.sort(function (a, b) {
    const av = a[key] === null || a[key] === undefined ? '' : String(a[key]);
    const bv = b[key] === null || b[key] === undefined ? '' : String(b[key]);
    if (av === '' && bv !== '') return 1;
    if (bv === '' && av !== '') return -1;
    return dir * av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' });
  });
}

function ldNbRenderTable() {
  const body = document.getElementById('ldNeighboursBody');
  if (!body) return;
  body.textContent = '';
  const rows = ldNbSorted();

  if (rows.length === 0) {
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = 5;
    td.style.textAlign = 'center';
    td.style.padding = '24px';
    td.style.color = 'var(--text-muted)';
    td.textContent = 'No LLDP neighbours currently detected.';
    tr.appendChild(td);
    body.appendChild(tr);
    return;
  }

  rows.forEach(function (row) {
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
  const wrapTable = document.getElementById('ldNeighboursTableWrap');
  const wrapRaw = document.getElementById('ldRawWrap');
  const toggleBtn = document.getElementById('ldNbToggle');

  if (wrapTable) wrapTable.hidden = view !== 'table';
  if (wrapRaw) wrapRaw.hidden = view !== 'raw';
  if (toggleBtn) toggleBtn.textContent = view === 'raw' ? 'Show table' : 'Show raw output';
}

async function ldNbLoadRaw(note) {
  try {
    const body = await ldNbFetch('text');
    if (body.csrf) LD_CSRF = body.csrf;
    const data = (body.result && body.result.data) || {};
    const text = data.neighbours || '';
    const rawEl = document.getElementById('ldNeighbours');
    if (rawEl) {
      rawEl.textContent = (body.ok && text.trim()) ? text : '— no LLDP neighbours are currently visible —';
    }
    LD_NB_RAW_LOADED = true;
    if (!body.ok && note) note.textContent = body.message || 'the daemon rejected the request';
    return body;
  } catch (e) {
    const rawEl = document.getElementById('ldNeighbours');
    if (rawEl) rawEl.textContent = '— unavailable —';
    LD_NB_RAW_LOADED = true;
    if (note) note.textContent = 'the raw listing is unavailable';
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
    await ldNeighbours();
  }
}

async function ldNeighbours() {
  const note = document.getElementById('ldNeighboursNote');
  if (note) note.textContent = 'Scanning for neighbours…';
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
    if (note) note.textContent = LD_NB_ROWS.length + (LD_NB_ROWS.length === 1 ? ' neighbour' : ' neighbours') + ' discovered.';
    ldNbWarnings(warnings);
    return;
  }

  // Nothing structured to show: fallback to raw listing
  ldNbShowView('raw');
  const raw = await ldNbLoadRaw(note);
  const rawWarnings = (raw && raw.result && raw.result.warnings) || [];
  if (!raw) {
    warnings.push('the neighbour table could not be read');
  } else if (raw.ok && note) {
    note.textContent = (body && !body.ok && body.message)
      ? body.message + ' — showing the raw listing.'
      : 'No neighbours were parsed — showing the raw listing.';
  }
  ldNbWarnings(warnings.concat(rawWarnings));
}

async function ldApply(event) {
  event.preventDefault();
  const note = document.getElementById('ldStatusNote');
  const formNote = document.getElementById('ldFormNote');
  if (note) note.textContent = 'applying…';
  if (formNote) {
    formNote.textContent = 'Applying settings…';
    formNote.style.color = 'var(--text-muted)';
  }

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

  if (formNote) {
    formNote.textContent = body.ok ? (body.message || 'Settings applied successfully.') : ('Failed: ' + (body.message || 'unknown error'));
    formNote.style.color = body.ok ? '#22c55e' : '#ef4444';
  }
  if (note) note.textContent = body.ok ? '' : 'apply failed';
  if (body.result) ldRender(body.result);
  return false;
}

ldRefresh();
ldNeighbours();
</script>

<?= $this->endSection() ?>
