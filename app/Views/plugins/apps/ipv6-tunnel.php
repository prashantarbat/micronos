<?php
/**
 * IPv6 6in4 tunnel-broker page — the configuration-only plugin's UI surface.
 *
 * Rendered by `Plugins::show()` in place of the generic metadata page, so an
 * installed plugin's nav entry lands on something usable.
 *
 * Everything here is a *request*: the daemon fixes the plugin id, validates
 * every address before it writes the generated unit, and keeps the broker's
 * update key in a 0600 file beside its own state. The key is typed straight
 * through — the page never stores it, shows it again, or sends it anywhere but
 * the daemon. Nothing here builds an `ip` command.
 *
 * @var array<string, mixed> $plugin
 */
$statusUrl  = base_url('plugins/ipv6-tunnel/status');
$applyUrl   = base_url('plugins/ipv6-tunnel/apply');
$updateUrl  = base_url('plugins/ipv6-tunnel/update');
?>
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.t6-grid { display:grid; grid-template-columns:220px 1fr; gap:14px; }
@media (max-width: 820px) { .t6-grid { grid-template-columns:1fr; } }
.t6-meta dt { color:var(--text-muted); font-weight:600; }
.t6-meta dd { margin:0; color:var(--text-primary); font-family:var(--font-mono); font-size:.74rem; word-break:break-all; }
.t6-led { display:inline-block; width:9px; height:9px; border-radius:50%; background:#6b7280; box-shadow:0 0 6px rgba(0,0,0,.4); }
.t6-led.on { background:#22c55e; box-shadow:0 0 8px rgba(34,197,94,.65); }
.t6-led.off { background:#ef4444; box-shadow:0 0 8px rgba(239,68,68,.5); }
</style>

<div class="page-header animate-in">
  <div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <h1 class="page-title">IPv6 6in4 Tunnel Broker</h1>
      <span class="badge badge-success" style="font-size:0.7rem;">CLASS B</span>
      <span class="badge badge-muted" style="font-size:0.7rem;"><?= esc($plugin['id']) ?> · v<?= esc($plugin['version']) ?></span>
      <span id="t6StateBadge" class="badge badge-muted" style="font-size:0.7rem;">CHECKING…</span>
    </div>
    <p class="page-subtitle">
      A free 6in4 (SIT) tunnel from a broker such as tunnelbroker.net or route64.org: the broker hands you a client
      address, its endpoint and a routed prefix; this plugin configures the kernel tunnel from those values. No
      package to install — one generated systemd unit with fixed <code>ip</code> argv.
    </p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
    <button type="button" class="btn btn-ghost btn-sm" onclick="t6Refresh()">Refresh status</button>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:16px 18px; margin-bottom:16px;">
  <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
    <span class="t6-led" id="t6Led"></span>
    <div style="font-weight:700; font-size:0.92rem;">Tunnel status</div>
    <span id="t6StatusNote" style="font-size:.74rem; color:var(--text-muted);"></span>
  </div>

  <div class="t6-grid">
    <dl class="t6-meta" style="display:grid; grid-template-columns:1fr; gap:3px;">
      <dt>Unit</dt><dd id="t6Unit">—</dd>
      <dt>Broker endpoint</dt><dd id="t6Endpoint">—</dd>
      <dt>Local endpoint</dt><dd id="t6Local">—</dd>
      <dt>Client address</dt><dd id="t6Client">—</dd>
    </dl>
    <div>
      <dl class="t6-meta" style="display:grid; grid-template-columns:170px 1fr; gap:3px 12px;">
        <dt>Broker IPv6 (peer)</dt><dd id="t6Server">—</dd>
        <dt>Routed prefix</dt><dd id="t6Routed">—</dd>
        <dt>MTU</dt><dd id="t6Mtu">—</dd>
        <dt>Update key</dt><dd id="t6Key">—</dd>
        <dt>Last endpoint update</dt><dd id="t6LastUpdate">—</dd>
      </dl>
      <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
        <button type="button" class="btn btn-ghost btn-sm" onclick="t6Update()"
                title="Send this appliance's current public IPv4 to the broker using the stored update key">
          Update endpoint at broker
        </button>
      </div>
      <div id="t6Warnings" style="margin-top:10px; font-size:.72rem; color:#f59e0b;"></div>
      <div id="t6Steps" style="margin-top:8px; font-size:.7rem; color:var(--text-muted); font-family:var(--font-mono);"></div>
    </div>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px; margin-bottom:16px;">
  <div style="font-weight:700; font-size:0.92rem; margin-bottom:2px;">Tunnel configuration</div>
  <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:12px;">
    Copy the values from the broker's tunnel page. <strong>Endpoint updates contact a third-party service</strong>:
    “Update endpoint at broker” sends this appliance's current public IPv4 address and its stored update key to
    <code>ipv4.tunnelbroker.net</code> over HTTPS (dyndns2-style) — nothing else leaves the appliance, and the key
    itself is kept in a daemon-side 0600 file, never in the database and never shown again. The tunnel's routes are
    added; the appliance's <em>default</em> route is deliberately left alone — add one on the Routing page if you
    want all IPv6 traffic to ride the tunnel. Applying also recompiles and reloads the firewall so the tunnel's
    device is permitted while the plugin is enabled.
  </div>
  <form id="t6Form" onsubmit="return t6Apply(event)">
    <div class="repo-grid">
      <div class="repo-field">
        <label for="t6Broker">Broker</label>
        <select id="t6Broker" name="broker">
          <option value="tunnelbroker.net">tunnelbroker.net (Hurricane Electric)</option>
          <option value="route64.org">route64.org</option>
          <option value="other">Other 6in4 broker</option>
        </select>
      </div>
      <div class="repo-field">
        <label for="t6EndpointInput">Broker IPv4 endpoint</label>
        <input type="text" id="t6EndpointInput" name="endpoint_v4" placeholder="216.66.80.30" spellcheck="false"
               pattern="[0-9]{1,3}(\.[0-9]{1,3}){3}" required>
      </div>
      <div class="repo-field">
        <label for="t6LocalInput">Local IPv4 <span style="color:var(--text-muted);">(“auto” to detect)</span></label>
        <input type="text" id="t6LocalInput" name="local_v4" placeholder="auto" spellcheck="false" value="auto">
        <div style="font-size:.68rem; color:var(--text-muted); margin-top:2px;">
          This appliance's public IPv4 — the side the broker sees the tunnel arrive from.
        </div>
      </div>
      <div class="repo-field">
        <label for="t6ClientInput">Client IPv6 (with prefix)</label>
        <input type="text" id="t6ClientInput" name="client_v6" placeholder="2001:470:1f08:a2a::2/64"
               spellcheck="false" required>
      </div>
      <div class="repo-field">
        <label for="t6ServerInput">Broker IPv6 (peer)</label>
        <input type="text" id="t6ServerInput" name="server_v6" placeholder="2001:470:1f09:a2a::1"
               spellcheck="false" required>
      </div>
      <div class="repo-field">
        <label for="t6RoutedInput">Routed prefix <span style="color:var(--text-muted);">(optional)</span></label>
        <input type="text" id="t6RoutedInput" name="routed_prefix" placeholder="2001:470:1f0b:a2a::/64"
               spellcheck="false">
      </div>
      <div class="repo-field">
        <label for="t6MtuInput">MTU</label>
        <input type="number" id="t6MtuInput" name="mtu" value="1480" min="1280" max="9000">
        <div style="font-size:.68rem; color:var(--text-muted); margin-top:2px;">
          1480 is the usual 6in4 MTU (1500 minus the 20-byte IPv4 header).
        </div>
      </div>
      <div class="repo-field">
        <label for="t6Enabled" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="t6Enabled" name="enabled" checked>
          Tunnel enabled
        </label>
        <div style="font-size:.68rem; color:var(--text-muted);">
          Unchecking stops the unit and removes the generated file.
        </div>
      </div>
    </div>

    <div style="font-weight:700; font-size:.84rem; margin-top:16px; margin-bottom:6px;">Broker endpoint updates (optional)</div>
    <div class="repo-grid">
      <div class="repo-field">
        <label for="t6User">Update username</label>
        <input type="text" id="t6User" name="update_user" spellcheck="false" autocomplete="off"
               placeholder="your broker account">
      </div>
      <div class="repo-field">
        <label for="t6TunnelId">Tunnel id / hostname</label>
        <input type="text" id="t6TunnelId" name="update_tunnel" spellcheck="false" autocomplete="off"
               placeholder="12056">
      </div>
      <div class="repo-field">
        <label for="t6KeyInput">Update key</label>
        <input type="password" id="t6KeyInput" name="update_key" autocomplete="off" spellcheck="false"
               placeholder="leave blank to keep the stored key">
        <div style="font-size:.68rem; color:var(--text-muted); margin-top:2px;">
          Stored 0600 daemon-side; never echoed back. Leave blank to keep the stored key.
          <button type="button" class="btn btn-ghost btn-sm" style="padding:1px 8px; font-size:.66rem;"
                  onclick="t6ClearKey()">Clear stored key</button>
        </div>
      </div>
    </div>
    <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary btn-sm">Apply configuration</button>
    </div>
    <div id="t6FormNote" style="margin-top:10px; font-size:.74rem;"></div>
  </form>
</div>

<script>
const T6_STATUS_URL = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
const T6_APPLY_URL  = <?= json_encode($applyUrl, JSON_UNESCAPED_SLASHES) ?>;
const T6_UPDATE_URL = <?= json_encode($updateUrl, JSON_UNESCAPED_SLASHES) ?>;
let T6_CSRF = <?= json_encode(csrf_hash()) ?>;

function t6Led(el, on) {
  el.classList.remove('on', 'off');
  el.classList.add(on ? 'on' : 'off');
}

function t6Render(result) {
  const data = (result && result.data) || {};
  const badge = document.getElementById('t6StateBadge');
  const running = data.unit_file_present && result && result.state === 'running';

  badge.textContent = running ? 'TUNNEL UP' : (data.unit_file_present ? 'TUNNEL DOWN' : 'NOT APPLIED');
  badge.className = 'badge ' + (running ? 'badge-success' : (data.unit_file_present ? 'badge-warning' : 'badge-muted'));
  t6Led(document.getElementById('t6Led'), !!running);

  const set = (id, value, fallback) => {
    document.getElementById(id).textContent = (value === undefined || value === null || value === '') ? (fallback || '—') : value;
  };
  set('t6Unit', data.unit_file_present ? (result.state === 'running' ? 'micron-6in4 · running' : 'micron-6in4 · stopped') : 'not generated');
  set('t6Endpoint', data.endpoint_v4, '—');
  set('t6Local', data.resolved_local_v4 || data.local_v4, '—');
  set('t6Client', data.client_v6, '—');
  set('t6Server', data.server_v6, '—');
  set('t6Routed', data.routed_prefix, 'none');
  set('t6Mtu', data.mtu, '—');
  set('t6Key', data.update_key_stored ? 'stored (0600, never shown)' : 'not stored');
  const last = data.last_update;
  set('t6LastUpdate', last ? (last.result + ' · ' + last.at) : 'never');

  // Pre-fill the form from the applied state (the key is never part of it).
  if (data.endpoint_v4) document.getElementById('t6EndpointInput').value = data.endpoint_v4;
  if (data.local_v4) document.getElementById('t6LocalInput').value = data.local_v4;
  if (data.client_v6) document.getElementById('t6ClientInput').value = data.client_v6;
  if (data.server_v6) document.getElementById('t6ServerInput').value = data.server_v6;
  if (data.routed_prefix) document.getElementById('t6RoutedInput').value = data.routed_prefix;
  if (data.mtu) document.getElementById('t6MtuInput').value = data.mtu;
  if (data.update_user) document.getElementById('t6User').value = data.update_user;
  if (data.update_tunnel) document.getElementById('t6TunnelId').value = data.update_tunnel;
  if (data.enabled !== undefined) document.getElementById('t6Enabled').checked = data.enabled === true;

  const warnings = (result && result.warnings) || [];
  document.getElementById('t6Warnings').innerHTML = warnings.map(w => '⚠ ' + w).join('<br>');
  const steps = (result && result.steps) || [];
  document.getElementById('t6Steps').innerHTML = steps.map(s => '· ' + s).join('<br>');
}

async function t6Call(url, options) {
  const response = await MicronScheduler.fetch(url, options);
  return response.json();
}

async function t6Refresh() {
  const note = document.getElementById('t6StatusNote');
  note.textContent = 'reading…';
  try {
    const body = await t6Call(T6_STATUS_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (body.csrf) T6_CSRF = body.csrf;
    t6Render(body.result);
    note.textContent = body.ok ? '' : (body.message || 'the daemon rejected the request');
  } catch (e) {
    note.textContent = 'status unavailable';
  }
}

async function t6Apply(event) {
  event.preventDefault();
  const note = document.getElementById('t6StatusNote');
  const formNote = document.getElementById('t6FormNote');
  note.textContent = 'applying…';
  formNote.textContent = '';
  formNote.style.color = 'var(--text-muted)';

  const payload = {
    enabled: document.getElementById('t6Enabled').checked,
    broker: document.getElementById('t6Broker').value,
    endpoint_v4: document.getElementById('t6EndpointInput').value.trim(),
    local_v4: document.getElementById('t6LocalInput').value.trim(),
    client_v6: document.getElementById('t6ClientInput').value.trim(),
    server_v6: document.getElementById('t6ServerInput').value.trim(),
    routed_prefix: document.getElementById('t6RoutedInput').value.trim(),
    mtu: parseInt(document.getElementById('t6MtuInput').value, 10) || 1480,
    update_user: document.getElementById('t6User').value.trim(),
    update_tunnel: document.getElementById('t6TunnelId').value.trim(),
  };
  // The key is included only when typed: blank means "keep the stored one".
  const key = document.getElementById('t6KeyInput').value;
  if (key !== '') payload.update_key = key;

  const body = await t6Call(T6_APPLY_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': T6_CSRF },
    body: JSON.stringify(payload),
  });
  if (body.csrf) T6_CSRF = body.csrf;

  // The key was handed over either way — clear it from the DOM immediately.
  document.getElementById('t6KeyInput').value = '';

  formNote.textContent = body.ok ? (body.message || 'applied.') : ('failed: ' + (body.message || 'unknown error'));
  formNote.style.color = body.ok ? '#22c55e' : '#ef4444';
  note.textContent = body.ok ? '' : 'apply failed';
  if (body.result) t6Render(body.result);
  return false;
}

async function t6ClearKey() {
  const note = document.getElementById('t6StatusNote');
  const formNote = document.getElementById('t6FormNote');
  note.textContent = 'clearing the stored key…';

  // A cleared key needs the rest of the config only for validation, so the
  // current form values travel with an explicit empty key.
  const body = await t6Call(T6_APPLY_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': T6_CSRF },
    body: JSON.stringify({
      enabled: document.getElementById('t6Enabled').checked,
      broker: document.getElementById('t6Broker').value,
      endpoint_v4: document.getElementById('t6EndpointInput').value.trim(),
      local_v4: document.getElementById('t6LocalInput').value.trim(),
      client_v6: document.getElementById('t6ClientInput').value.trim(),
      server_v6: document.getElementById('t6ServerInput').value.trim(),
      routed_prefix: document.getElementById('t6RoutedInput').value.trim(),
      mtu: parseInt(document.getElementById('t6MtuInput').value, 10) || 1480,
      update_user: document.getElementById('t6User').value.trim(),
      update_tunnel: document.getElementById('t6TunnelId').value.trim(),
      update_key: '',
    }),
  });
  if (body.csrf) T6_CSRF = body.csrf;
  document.getElementById('t6KeyInput').value = '';

  formNote.textContent = body.ok ? (body.message || 'stored key cleared.') : ('failed: ' + (body.message || 'unknown error'));
  formNote.style.color = body.ok ? '#22c55e' : '#ef4444';
  note.textContent = body.ok ? '' : 'clear failed';
  if (body.result) t6Render(body.result);
}

async function t6Update() {
  const note = document.getElementById('t6StatusNote');
  const formNote = document.getElementById('t6FormNote');
  note.textContent = 'contacting the broker…';

  const body = await t6Call(T6_UPDATE_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': T6_CSRF },
    body: JSON.stringify({}),
  });
  if (body.csrf) T6_CSRF = body.csrf;

  formNote.textContent = body.ok ? (body.message || 'broker answered.') : ('broker said: ' + (body.message || 'unknown error'));
  formNote.style.color = body.ok ? '#22c55e' : '#ef4444';
  note.textContent = body.ok ? '' : 'update failed';
  if (body.result) t6Render(body.result);
}

t6Refresh();
</script>

<?= $this->endSection() ?>
