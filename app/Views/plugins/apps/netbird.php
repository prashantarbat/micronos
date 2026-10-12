<?php
/**
 * NetBird overlay page — the Class C plugin's own UI surface.
 *
 * Rendered by `Plugins::show()` in place of the generic metadata page whenever
 * `Views/plugins/apps/<id>.php` exists, so an installed plugin's nav entry lands
 * on something usable.
 *
 * Everything here is a *request*: the daemon fixes the plugin id, validates each
 * parameter and owns the agent. The page never builds a netbird command, and the
 * setup key is typed straight through to the daemon, which stages it as a 0600
 * file and deletes it — the page does not store it, show it again, or log it.
 *
 * @var array<string, mixed> $plugin
 */
$statusUrl = base_url('plugins/netbird/status');
$controlUrl = base_url('plugins/netbird/control');
?>
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.nb-grid { display:grid; grid-template-columns:220px 1fr; gap:14px; }
@media (max-width: 820px) { .nb-grid { grid-template-columns:1fr; } }
.nb-meta dt { color:var(--text-muted); font-weight:600; }
.nb-meta dd { margin:0; color:var(--text-primary); font-family:var(--font-mono); font-size:.74rem; word-break:break-all; }
.nb-led { display:inline-block; width:9px; height:9px; border-radius:50%; background:#6b7280; box-shadow:0 0 6px rgba(0,0,0,.4); }
.nb-led.on { background:#22c55e; box-shadow:0 0 8px rgba(34,197,94,.65); }
.nb-led.off { background:#ef4444; box-shadow:0 0 8px rgba(239,68,68,.5); }
</style>

<div class="page-header animate-in">
  <div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <h1 class="page-title">NetBird Mesh VPN</h1>
      <span class="badge badge-danger" style="font-size:0.7rem;">CLASS C</span>
      <span class="badge badge-muted" style="font-size:0.7rem;"><?= esc($plugin['id']) ?> · v<?= esc($plugin['version']) ?></span>
      <span id="nbStateBadge" class="badge badge-muted" style="font-size:0.7rem;">CHECKING…</span>
    </div>
    <p class="page-subtitle">
      WireGuard-based overlay. This appliance joins a NetBird deployment as a peer; the agent talks to a management
      service outside the appliance and the overlay changes the routing of live traffic.
    </p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
    <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
    <button type="button" class="btn btn-ghost btn-sm" onclick="nbRefresh()">Refresh status</button>
    <?= \App\Libraries\ServiceStatus::widget('netbird') ?>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:16px 18px; margin-bottom:16px;">
  <div style="font-weight:700; font-size:0.92rem; margin-bottom:2px;">This is a Class C extension</div>
  <div style="font-size:.78rem; color:var(--text-secondary); line-height:1.55;">
    Three things are true of NetBird that are not true of a distribution daemon, and they were approved when it was
    installed: the package comes from <strong>NetBird's own apt repository</strong> (pinned below the distribution, with
    the protected core pinned to this appliance's release, so it can never supply or upgrade a core dependency); the peer
    registers with a <strong>management service outside this appliance</strong> — the vendor's, or your own if you point
    it at one; and the overlay <strong>alters routing and the firewall interface group</strong> for live traffic.
    Enrolment is the moment the appliance starts talking to that service.
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:16px 18px; margin-bottom:16px;">
  <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
    <span class="nb-led" id="nbLed"></span>
    <div style="font-weight:700; font-size:0.92rem;">Peer status</div>
    <span id="nbStatusNote" style="font-size:.74rem; color:var(--text-muted);"></span>
  </div>

  <div class="nb-grid">
    <dl class="nb-meta" style="display:grid; grid-template-columns:1fr; gap:3px;">
      <dt>Overlay IP</dt><dd id="nbIp">—</dd>
      <dt>Interface</dt><dd id="nbIface">—</dd>
      <dt>FQDN</dt><dd id="nbFqdn">—</dd>
      <dt>Public key</dt><dd id="nbKey" style="font-size:.68rem;">—</dd>
      <dt>Agent version</dt><dd id="nbVersion">—</dd>
    </dl>
    <div>
      <dl class="nb-meta" style="display:grid; grid-template-columns:150px 1fr; gap:3px 12px;">
        <dt>Management</dt><dd id="nbManagement">—</dd>
        <dt>Signal</dt><dd id="nbSignal">—</dd>
        <dt>Peers connected</dt><dd id="nbPeers">—</dd>
      </dl>
      <div id="nbConnect" style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
        <button type="button" class="btn btn-primary btn-sm" onclick="nbControl('up')" title="Connect this peer using its stored configuration">Connect</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="nbControl('down')" title="Disconnect the peer without removing its enrolment">Disconnect</button>
      </div>
      <div id="nbSteps" style="margin-top:10px; font-size:.7rem; color:var(--text-muted); font-family:var(--font-mono);"></div>
    </div>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px; margin-bottom:16px;">
  <div style="font-weight:700; font-size:0.92rem; margin-bottom:2px;">Enrol this appliance</div>
  <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:12px;">
    A setup key from the NetBird dashboard registers this peer. The key is passed to the agent through a private
    0600 file — never in the command line, never written to the appliance's configuration database, never logged, and
    never shown again after this form is submitted.
  </div>
  <form id="nbEnrollForm" onsubmit="return nbEnroll(event)">
    <div class="repo-grid">
      <div class="repo-field">
        <label for="nbSetupKey">Setup key</label>
        <input type="password" id="nbSetupKey" name="setup_key" autocomplete="off" spellcheck="false"
               placeholder="from the NetBird dashboard" required>
      </div>
      <div class="repo-field">
        <label for="nbManagementUrl">Management URL <span style="color:var(--text-muted);">(optional)</span></label>
        <input type="url" id="nbManagementUrl" name="management_url" placeholder="https://api.netbird.io:443"
               pattern="https://.*">
        <div style="font-size:.68rem; color:var(--text-muted); margin-top:2px;">
          For a self-hosted deployment. Defaults to the vendor's cloud if left empty.
        </div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:12px;">Enrol peer</button>
  </form>
</div>

<script>
const NB_STATUS_URL  = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
const NB_CONTROL_URL = <?= json_encode($controlUrl, JSON_UNESCAPED_SLASHES) ?>;
let NB_CSRF = <?= json_encode(csrf_hash()) ?>;

function nbLed(el, on) {
  el.classList.remove('on', 'off');
  el.classList.add(on ? 'on' : 'off');
}

function nbRender(result) {
  const data = (result && result.data) || {};
  const badge = document.getElementById('nbStateBadge');
  const connected = data.management_connected === true;

  badge.textContent = connected ? 'CONNECTED' : (data.fqdn ? 'ENROLLED · DISCONNECTED' : 'NOT ENROLLED');
  badge.className = 'badge ' + (connected ? 'badge-success' : (data.fqdn ? 'badge-warning' : 'badge-muted'));
  nbLed(document.getElementById('nbLed'), connected);

  const set = (id, value, fallback) => {
    document.getElementById(id).textContent = (value === undefined || value === null || value === '') ? (fallback || '—') : value;
  };
  set('nbIp', data.ip, '—');
  set('nbIface', data.interface, '—');
  set('nbFqdn', data.fqdn, '—');
  set('nbKey', data.public_key, '—');
  set('nbVersion', data.daemon_version, '—');
  set('nbManagement', (data.management_connected === null || data.management_connected === undefined)
    ? (data.management_url || 'unknown') : (data.management_url || '') + (data.management_connected ? ' · connected' : ' · unreachable'));
  set('nbSignal', data.signal_connected === null || data.signal_connected === undefined
    ? 'unknown' : (data.signal_connected ? 'connected' : 'unreachable'));
  set('nbPeers', (data.peers_total === undefined || data.peers_total === null)
    ? '—' : data.peers_connected + ' of ' + data.peers_total);

  const steps = (result && result.steps) || [];
  document.getElementById('nbSteps').innerHTML = steps.map(s => '· ' + s).join('<br>');
}

async function nbCall(url, options) {
  const response = await MicronScheduler.fetch(url, options);
  return response.json();
}

async function nbRefresh() {
  const note = document.getElementById('nbStatusNote');
  note.textContent = 'reading…';
  try {
    const body = await nbCall(NB_STATUS_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (body.csrf) NB_CSRF = body.csrf;
    nbRender(body.result);
    note.textContent = body.ok ? '' : (body.message || 'the daemon rejected the request');
  } catch (e) {
    note.textContent = 'status unavailable';
  }
}

async function nbControl(action) {
  const note = document.getElementById('nbStatusNote');
  note.textContent = action === 'up' ? 'connecting…' : 'disconnecting…';
  const body = await nbCall(NB_CONTROL_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': NB_CSRF },
    body: JSON.stringify({ action: action }),
  });
  if (body.csrf) NB_CSRF = body.csrf;
  nbRender(body.result);
  note.textContent = body.ok ? body.message || '' : ('failed: ' + (body.message || 'unknown error'));
}

async function nbEnroll(event) {
  event.preventDefault();
  const field = document.getElementById('nbSetupKey');
  const management = document.getElementById('nbManagementUrl');
  const note = document.getElementById('nbStatusNote');
  note.textContent = 'enrolling…';

  const body = await nbCall(NB_CONTROL_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': NB_CSRF },
    body: JSON.stringify({ action: 'enroll', setup_key: field.value, management_url: management.value }),
  });
  if (body.csrf) NB_CSRF = body.csrf;

  // Clear the field either way: the key was handed over, and there is no reason
  // for it to sit in the DOM afterwards.
  field.value = '';
  nbRender(body.result);
  note.textContent = body.ok ? (body.message || 'enrolment submitted') : ('enrolment failed: ' + (body.message || 'unknown error'));
  return false;
}

nbRefresh();
</script>

<?= $this->endSection() ?>
