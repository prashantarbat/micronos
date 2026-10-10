<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.ipf-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:start; }
@media (max-width:1100px) { .ipf-grid { grid-template-columns:1fr; } }
.ipf-field { display:flex; flex-direction:column; gap:5px; margin-bottom:12px; }
.ipf-field label { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); font-weight:600; }
.ipf-field input, .ipf-field select {
  background:var(--color-surface-2,#101820); border:1px solid var(--color-border); border-radius:6px;
  padding:8px 10px; color:var(--text-primary); font-family:var(--font-mono); font-size:.82rem;
}
.ipf-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.ipf-result { display:none; margin-top:14px; padding:16px; border-radius:8px; border:1px solid var(--color-border); background:rgba(6,182,212,.06); }
.ipf-result.is-error { background:rgba(239,68,68,.08); border-color:rgba(239,68,68,.35); }
.ipf-result.is-ok { display:block; }
.ipf-mbps { font-size:2.4rem; font-weight:800; font-family:var(--font-mono); color:var(--color-cyan,#06b6d4); line-height:1; }
.ipf-mbps small { font-size:.9rem; color:var(--text-muted); font-weight:600; }
.ipf-stats { display:flex; gap:22px; flex-wrap:wrap; margin-top:12px; }
.ipf-stat { display:flex; flex-direction:column; gap:2px; }
.ipf-stat span:first-child { font-size:.66rem; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); }
.ipf-stat span:last-child { font-family:var(--font-mono); font-size:.95rem; color:var(--text-primary); }
.ipf-steps { margin-top:12px; font-family:var(--font-mono); font-size:.72rem; color:var(--text-muted); white-space:pre-wrap; }
</style>

<div class="page-header animate-in">
  <div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <h1 class="page-title"><?= esc($plugin['name']) ?></h1>
      <span class="badge badge-muted" style="font-size:0.7rem;">CLASS <?= esc($plugin['class']) ?></span>
      <span class="badge badge-info" style="font-size:0.7rem;">v<?= esc($plugin['version']) ?></span>
    </div>
    <p class="page-subtitle">Measure throughput to a peer, or stand up a time-bounded listening server for a single client</p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
    <?php if (! empty($plugin['units'])): ?>
      <?= \App\Libraries\ServiceStatus::pluginWidget(
            [['id' => $plugin['id'], 'name' => $plugin['name'], 'installed' => true, 'units' => $plugin['units']]],
            $states ?? [],
          ) ?>
    <?php endif; ?>
    <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
    <a href="<?= base_url('plugins/updates') ?>" class="btn btn-ghost btn-sm">Updates</a>
  </div>
</div>

<div class="ipf-grid">
  <!-- Client test -->
  <div class="card card-glass animate-in animate-in-delay-1" style="padding:18px;">
    <div style="font-weight:700; font-size:0.95rem; margin-bottom:4px;">Client throughput test</div>
    <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:14px;">
      Measure to a host running an iperf3 server (either the peer's own, or the on-demand server to the right).
    </div>

    <form id="ipfClientForm" autocomplete="off">
      <div class="ipf-field">
        <label for="ipfTarget">Target host / IP</label>
        <input type="text" id="ipfTarget" name="target" placeholder="203.0.113.10 or peer.example.net" required>
      </div>
      <div class="ipf-row">
        <div class="ipf-field">
          <label for="ipfPort">Port</label>
          <input type="number" id="ipfPort" name="port" value="5201" min="1" max="65535">
        </div>
        <div class="ipf-field">
          <label for="ipfDuration">Duration (s)</label>
          <input type="number" id="ipfDuration" name="duration" value="10" min="1" max="120">
        </div>
      </div>
      <div class="ipf-row">
        <div class="ipf-field">
          <label for="ipfProtocol">Protocol</label>
          <select id="ipfProtocol" name="protocol">
            <option value="tcp">TCP</option>
            <option value="udp">UDP</option>
          </select>
        </div>
        <div class="ipf-field">
          <label for="ipfDirection">Direction</label>
          <select id="ipfDirection" name="direction">
            <option value="upload">Upload (send)</option>
            <option value="download">Download (receive)</option>
            <option value="bidirectional">Bidirectional</option>
          </select>
        </div>
      </div>
      <div class="ipf-field">
        <label for="ipfStreams">Parallel streams</label>
        <input type="number" id="ipfStreams" name="streams" value="1" min="1" max="32">
      </div>
      <button type="submit" class="btn btn-primary btn-sm" id="ipfRunBtn">Run Test</button>
    </form>

    <div class="ipf-result" id="ipfClientResult"></div>
  </div>

  <!-- On-demand server -->
  <div class="card card-glass animate-in animate-in-delay-2" style="padding:18px;">
    <div style="font-weight:700; font-size:0.95rem; margin-bottom:4px;">On-demand server</div>
    <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:14px;">
      Bind a listening port for exactly one client, then exit — the appliance is never left with an
      unbounded listener. The persistent server unit is toggled from the daemon widget above.
    </div>

    <form id="ipfServerForm" autocomplete="off">
      <div class="ipf-row">
        <div class="ipf-field">
          <label for="ipfServerPort">Listen port</label>
          <input type="number" id="ipfServerPort" name="port" value="5201" min="1" max="65535">
        </div>
        <div class="ipf-field">
          <label for="ipfServerDuration">Max duration (s)</label>
          <input type="number" id="ipfServerDuration" name="duration" value="30" min="1" max="120">
        </div>
      </div>
      <button type="submit" class="btn btn-ghost btn-sm" id="ipfServerBtn">Start Listening Server</button>
    </form>

    <div class="ipf-result" id="ipfServerResult"></div>

    <div style="margin-top:16px; padding-top:14px; border-top:1px solid var(--color-border); font-size:.74rem; color:var(--text-muted);">
      <strong style="color:var(--text-secondary);">Plugin details</strong><br>
      Packages: <?php if ($plugin['packages'] === []): ?>—<?php else: ?><?php foreach ($plugin['packages'] as $pkg): ?><code><?= esc($pkg) ?></code> <?php endforeach; ?><?php endif; ?><br>
      Unit: <?php if ($plugin['units'] === []): ?>none<?php else: ?><?php foreach ($plugin['units'] as $u): ?><code><?= esc($u) ?></code><?php endforeach; ?><?php endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
  var RUN_URL = <?= json_encode(base_url('plugins/iperf3/run'), JSON_UNESCAPED_SLASHES) ?>;
  var CSRF = <?= json_encode(csrf_hash()) ?>;

  function setBusy(btn, busy, label) {
    if (busy) { btn.dataset.label = btn.textContent; btn.textContent = label; btn.disabled = true; }
    else { btn.textContent = btn.dataset.label || btn.textContent; btn.disabled = false; }
  }

  function fmt(n, suffix, digits) {
    if (n === null || n === undefined || n === '') { return '—'; }
    var v = Number(n);
    if (isNaN(v)) { return '—'; }
    return v.toFixed(digits === undefined ? 2 : digits) + (suffix || '');
  }

  function renderClient(box, res) {
    var r = res.result || {};
    box.classList.remove('is-error');
    box.classList.add('is-ok');
    box.innerHTML =
      '<div class="ipf-mbps">' + fmt(r.mbps, ' <small>Mbit/s</small>', 2) + '</div>' +
      '<div style="font-size:.74rem;color:var(--text-muted);margin-top:4px;">' +
        String(r.target || '') + ':' + String(r.port || '') + ' · ' +
        String((r.protocol || 'tcp').toUpperCase()) + ' · ' + String(r.direction || '') + ' · ' +
        String(r.duration || '') + 's · ' + String(r.streams || 1) + ' stream(s)' +
      '</div>' +
      '<div class="ipf-stats">' +
        '<div class="ipf-stat"><span>Jitter</span><span>' + fmt(r.jitter_ms, ' ms', 3) + '</span></div>' +
        '<div class="ipf-stat"><span>Loss</span><span>' + fmt(r.lost_percent, ' %', 2) + '</span></div>' +
        '<div class="ipf-stat"><span>Retransmits</span><span>' + (r.retransmits === null || r.retransmits === undefined ? '—' : String(r.retransmits)) + '</span></div>' +
      '</div>' +
      (r.steps && r.steps.length ? '<div class="ipf-steps">' + r.steps.join('\n') + '</div>' : '');
  }

  function renderServer(box, res) {
    box.classList.remove('is-error');
    box.classList.add('is-ok');
    box.innerHTML =
      '<div style="font-weight:700;color:var(--color-cyan,#06b6d4);">Listening server ready</div>' +
      '<div style="font-size:.8rem;margin-top:6px;">' + (res.message || '') + '</div>' +
      (res.result && res.result.steps ? '<div class="ipf-steps">' + res.result.steps.join('\n') + '</div>' : '');
  }

  function renderError(box, msg) {
    box.classList.add('is-ok', 'is-error');
    box.innerHTML = '<div style="color:#f87171;font-weight:600;">Test failed</div>' +
      '<div style="font-size:.8rem;margin-top:6px;">' + (msg || 'the daemon rejected the test') + '</div>';
  }

  function post(payload) {
    return MicronScheduler.fetch(RUN_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(payload),
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data && data.csrf) { CSRF = data.csrf; }
      return data;
    });
  }

  var clientForm = document.getElementById('ipfClientForm');
  clientForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var box = document.getElementById('ipfClientResult');
    var btn = document.getElementById('ipfRunBtn');
    box.classList.remove('is-ok', 'is-error');
    box.style.display = 'block';
    box.innerHTML = '<div style="font-size:.82rem;color:var(--text-muted);">Running throughput test…</div>';
    setBusy(btn, true, 'Testing…');
    post({
      mode: 'client',
      target: document.getElementById('ipfTarget').value.trim(),
      port: parseInt(document.getElementById('ipfPort').value, 10) || 5201,
      duration: parseInt(document.getElementById('ipfDuration').value, 10) || 10,
      protocol: document.getElementById('ipfProtocol').value,
      direction: document.getElementById('ipfDirection').value,
      streams: parseInt(document.getElementById('ipfStreams').value, 10) || 1,
    }).then(function (data) {
      if (data && data.ok) { renderClient(box, data); }
      else { renderError(box, data && data.message); }
    }).catch(function () { renderError(box, 'Network error contacting the management plane.'); })
      .finally(function () { setBusy(btn, false); });
  });

  var serverForm = document.getElementById('ipfServerForm');
  serverForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var box = document.getElementById('ipfServerResult');
    var btn = document.getElementById('ipfServerBtn');
    box.classList.remove('is-ok', 'is-error');
    box.style.display = 'block';
    box.innerHTML = '<div style="font-size:.82rem;color:var(--text-muted);">Starting listening server…</div>';
    setBusy(btn, true, 'Starting…');
    post({
      mode: 'server',
      port: parseInt(document.getElementById('ipfServerPort').value, 10) || 5201,
      duration: parseInt(document.getElementById('ipfServerDuration').value, 10) || 30,
    }).then(function (data) {
      if (data && data.ok) { renderServer(box, data); }
      else { renderError(box, data && data.message); }
    }).catch(function () { renderError(box, 'Network error contacting the management plane.'); })
      .finally(function () { setBusy(btn, false); });
  });
})();
</script>

<?= $this->endSection() ?>
