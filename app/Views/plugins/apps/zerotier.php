<?php
/**
 * ZeroTier overlay page — the Class C plugin's own UI surface.
 *
 * Rendered by `Plugins::show()` in place of the generic metadata page whenever
 * `Views/plugins/apps/<id>.php` exists, so an installed plugin's nav entry lands
 * on something usable.
 *
 * Everything here is a *request*: the daemon fixes the plugin id, validates the
 * network id and owns the agent. The page never builds a zerotier command — it
 * names a 16-character network id and nothing else.
 *
 * The joined networks arrive from the ZeroTier *controller*, so a network's name
 * is text an outside party chose. Every cell is therefore built as a text node,
 * never as markup.
 *
 * @var array<string, mixed> $plugin
 */
$statusUrl  = base_url('plugins/zerotier/status');
$controlUrl = base_url('plugins/zerotier/control');
?>
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.zt-grid { display:grid; grid-template-columns:220px 1fr; gap:14px; }
@media (max-width: 820px) { .zt-grid { grid-template-columns:1fr; } }
.zt-meta dt { color:var(--text-muted); font-weight:600; }
.zt-meta dd { margin:0; color:var(--text-primary); font-family:var(--font-mono); font-size:.74rem; word-break:break-all; }
.zt-led { display:inline-block; width:9px; height:9px; border-radius:50%; background:#6b7280; box-shadow:0 0 6px rgba(0,0,0,.4); }
.zt-led.on { background:#22c55e; box-shadow:0 0 8px rgba(34,197,94,.65); }
.zt-led.off { background:#ef4444; box-shadow:0 0 8px rgba(239,68,68,.5); }
.zt-net-table { width:100%; border-collapse:collapse; font-size:.76rem; }
.zt-net-table th { text-align:left; color:var(--text-muted); font-weight:600; padding:6px 8px; border-bottom:1px solid var(--border-color); font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; }
.zt-net-table td { padding:7px 8px; border-bottom:1px solid rgba(255,255,255,.04); font-family:var(--font-mono); font-size:.72rem; color:var(--text-secondary); vertical-align:middle; }
.zt-net-table td.zt-name { font-family:inherit; font-size:.78rem; color:var(--text-primary); }
.zt-empty { padding:14px 8px; font-size:.76rem; color:var(--text-muted); }
</style>

<div class="page-header animate-in">
  <div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <h1 class="page-title">ZeroTier Overlay</h1>
      <span class="badge badge-danger" style="font-size:0.7rem;">CLASS C</span>
      <span class="badge badge-muted" style="font-size:0.7rem;"><?= esc($plugin['id']) ?> · v<?= esc($plugin['version']) ?></span>
      <span id="ztStateBadge" class="badge badge-muted" style="font-size:0.7rem;">CHECKING…</span>
    </div>
    <p class="page-subtitle">
      Virtual-network overlay. This appliance joins one or more ZeroTier networks as a node; the agent talks to a
      controller outside the appliance and the overlay changes the routing of live traffic.
    </p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
    <button type="button" class="btn btn-ghost btn-sm" onclick="ztRefresh()">Refresh status</button>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:16px 18px; margin-bottom:16px;">
  <div style="font-weight:700; font-size:0.92rem; margin-bottom:2px;">This is a Class C extension</div>
  <div style="font-size:.78rem; color:var(--text-secondary); line-height:1.55;">
    Three things are true of ZeroTier that are not true of a distribution daemon, and they were approved when it was
    installed: the package comes from <strong>ZeroTier's own apt repository</strong> (pinned below the distribution, with
    the protected core pinned to this appliance's release, so it can never supply or upgrade a core dependency); the node
    registers with a <strong>controller outside this appliance</strong> — the vendor's, or your own if self-hosted; and
    the overlay <strong>alters routing and the firewall interface group</strong> for live traffic. Joining a network is
    the moment the appliance starts talking to that controller.
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:16px 18px; margin-bottom:16px;">
  <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
    <span class="zt-led" id="ztLed"></span>
    <div style="font-weight:700; font-size:0.92rem;">Node status</div>
    <span id="ztStatusNote" style="font-size:.74rem; color:var(--text-muted);"></span>
  </div>

  <div class="zt-grid">
    <dl class="zt-meta" style="display:grid; grid-template-columns:1fr; gap:3px;">
      <dt>Node address</dt><dd id="ztAddress">—</dd>
      <dt>Agent version</dt><dd id="ztVersion">—</dd>
      <dt>Service</dt><dd id="ztService">—</dd>
    </dl>
    <div>
      <dl class="zt-meta" style="display:grid; grid-template-columns:150px 1fr; gap:3px 12px;">
        <dt>Controller reachable</dt><dd id="ztOnline">—</dd>
        <dt>Networks joined</dt><dd id="ztJoined">—</dd>
      </dl>
      <div id="ztSteps" style="margin-top:10px; font-size:.7rem; color:var(--text-muted); font-family:var(--font-mono);"></div>
    </div>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px; margin-bottom:16px;">
  <div style="font-weight:700; font-size:0.92rem; margin-bottom:2px;">Joined networks</div>
  <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:12px;">
    One row per network this node belongs to. Leaving removes the membership — the node keeps its identity, and the
    controller may keep its authorization until a network administrator removes it.
  </div>
  <table class="zt-net-table">
    <thead>
      <tr>
        <th>Network id</th>
        <th>Name</th>
        <th>Status</th>
        <th>Type</th>
        <th>Interface</th>
        <th>Assigned addresses</th>
        <th style="text-align:right;">Action</th>
      </tr>
    </thead>
    <tbody id="ztNetworks">
      <tr><td class="zt-empty" colspan="7">reading…</td></tr>
    </tbody>
  </table>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px; margin-bottom:16px;">
  <div style="font-weight:700; font-size:0.92rem; margin-bottom:2px;">Join a network</div>
  <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:12px;">
    A network id is the 16-character hexadecimal string shown by the controller (the ZeroTier Central network id). The
    node also has to be authorized on the network before it can pass traffic; that approval happens on the controller,
    not here.
  </div>
  <form id="ztJoinForm" onsubmit="return ztJoin(event)">
    <div class="repo-grid">
      <div class="repo-field">
        <label for="ztNwid">Network id</label>
        <input type="text" id="ztNwid" name="nwid" autocomplete="off" spellcheck="false"
               placeholder="8056c2e21c000001" required minlength="16" maxlength="16"
               pattern="[0-9a-fA-F]{16}">
        <div style="font-size:.68rem; color:var(--text-muted); margin-top:2px;">
          Exactly 16 hexadecimal characters. The daemon refuses anything else.
        </div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:12px;">Join network</button>
  </form>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px;">
  <div style="font-weight:700; font-size:0.9rem; margin-bottom:8px;">Diagnostics</div>
  <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:10px;">
    The agent's own service unit is controllable here, and its journal is available beside it.
  </div>
  <?= \App\Libraries\ServiceStatus::widget(['zerotier-one'], ['zerotier-one' => 'ZeroTier One agent']) ?>
</div>

<script>
const ZT_STATUS_URL  = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
const ZT_CONTROL_URL = <?= json_encode($controlUrl, JSON_UNESCAPED_SLASHES) ?>;
let ZT_CSRF = <?= json_encode(csrf_hash()) ?>;

function ztLed(el, on) {
  el.classList.remove('on', 'off');
  el.classList.add(on ? 'on' : 'off');
}

function ztText(value, fallback) {
  return (value === undefined || value === null || value === '') ? (fallback || '—') : value;
}

function ztCell(text, className) {
  const cell = document.createElement('td');
  if (className) cell.className = className;
  // A network name is chosen by whoever controls the network, so it is written
  // as text and never as markup.
  cell.textContent = text;
  return cell;
}

function ztRenderNetworks(networks) {
  const body = document.getElementById('ztNetworks');
  body.textContent = '';

  if (!networks || networks.length === 0) {
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    cell.className = 'zt-empty';
    cell.colSpan = 7;
    cell.textContent = 'This node has not joined any network yet.';
    row.appendChild(cell);
    body.appendChild(row);
    return;
  }

  networks.forEach(function (network) {
    const row = document.createElement('tr');
    row.appendChild(ztCell(ztText(network.nwid), ''));
    row.appendChild(ztCell(ztText(network.name), 'zt-name'));
    row.appendChild(ztCell(ztText(network.status)));
    row.appendChild(ztCell(ztText(network.type)));
    row.appendChild(ztCell(ztText(network.device)));
    row.appendChild(ztCell(ztText(network.assigned)));

    const actions = document.createElement('td');
    actions.style.textAlign = 'right';
    const leave = document.createElement('button');
    leave.type = 'button';
    leave.className = 'btn btn-ghost btn-sm';
    leave.textContent = 'Leave';
    leave.title = 'Leave this network';
    leave.addEventListener('click', function () { ztLeave(network.nwid); });
    actions.appendChild(leave);
    row.appendChild(actions);

    body.appendChild(row);
  });
}

function ztRender(result) {
  const data = (result && result.data) || {};
  const badge = document.getElementById('ztStateBadge');
  const online = data.online === true;

  badge.textContent = online ? 'ONLINE' : (data.online === false ? 'OFFLINE' : 'UNKNOWN');
  badge.className = 'badge ' + (online ? 'badge-success' : (data.online === false ? 'badge-danger' : 'badge-muted'));
  ztLed(document.getElementById('ztLed'), online);

  const set = (id, value, fallback) => {
    document.getElementById(id).textContent = ztText(value, fallback);
  };
  set('ztAddress', data.address);
  set('ztVersion', data.version);
  set('ztService', result && result.state ? result.state : '—');
  set('ztOnline', data.online === null || data.online === undefined
    ? 'unknown' : (data.online ? 'yes' : 'no'));
  set('ztJoined', data.networks_joined === undefined || data.networks_joined === null
    ? '0' : String(data.networks_joined));

  ztRenderNetworks(data.networks || []);

  const steps = (result && result.steps) || [];
  document.getElementById('ztSteps').textContent = steps.map(s => '· ' + s).join('\n');
}

async function ztCall(url, options) {
  const response = await MicronScheduler.fetch(url, options);
  return response.json();
}

async function ztRefresh() {
  const note = document.getElementById('ztStatusNote');
  note.textContent = 'reading…';
  try {
    const body = await ztCall(ZT_STATUS_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (body.csrf) ZT_CSRF = body.csrf;
    ztRender(body.result);
    note.textContent = body.ok
      ? ((body.result && body.result.warnings && body.result.warnings.length) ? body.result.warnings[0] : '')
      : (body.message || 'the daemon rejected the request');
  } catch (e) {
    note.textContent = 'status unavailable';
  }
}

async function ztControl(action, nwid) {
  const note = document.getElementById('ztStatusNote');
  note.textContent = action === 'join' ? 'joining…' : 'leaving…';
  const body = await ztCall(ZT_CONTROL_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': ZT_CSRF },
    body: JSON.stringify({ action: action, nwid: nwid }),
  });
  if (body.csrf) ZT_CSRF = body.csrf;
  ztRender(body.result);
  note.textContent = body.ok ? (body.message || '') : ('failed: ' + (body.message || 'unknown error'));
  return body.ok;
}

async function ztJoin(event) {
  event.preventDefault();
  const field = document.getElementById('ztNwid');
  const ok = await ztControl('join', field.value.trim().toLowerCase());
  if (ok) field.value = '';
  return false;
}

function ztLeave(nwid) {
  ztControl('leave', nwid);
}

ztRefresh();
</script>

<?= $this->endSection() ?>
