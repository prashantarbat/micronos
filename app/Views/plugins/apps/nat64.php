<?php
/**
 * NAT64 / DNS64 page — the Class B plugin's own UI surface.
 *
 * Rendered by `Plugins::show()` in place of the generic metadata page, so an
 * installed plugin's nav entry lands on something usable.
 *
 * Everything here is a *request*: the daemon fixes the plugin id, validates
 * every CIDR before it writes a byte or runs a command, gates the DNS64 half
 * through `unbound-checkconf` with rollback, and never controls the protected
 * resolver unit itself. The page builds no command and writes no file.
 *
 * @var array<string, mixed> $plugin
 */
$statusUrl = base_url('plugins/nat64/status');
$applyUrl  = base_url('plugins/nat64/apply');
?>
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.n6-grid { display:grid; grid-template-columns:220px 1fr; gap:14px; }
@media (max-width: 820px) { .n6-grid { grid-template-columns:1fr; } }
.n6-meta dt { color:var(--text-muted); font-weight:600; }
.n6-meta dd { margin:0; color:var(--text-primary); font-family:var(--font-mono); font-size:.74rem; word-break:break-all; }
.n6-led { display:inline-block; width:9px; height:9px; border-radius:50%; background:#6b7280; box-shadow:0 0 6px rgba(0,0,0,.4); }
.n6-led.on { background:#22c55e; box-shadow:0 0 8px rgba(34,197,94,.65); }
.n6-led.off { background:#ef4444; box-shadow:0 0 8px rgba(239,68,68,.5); }
</style>

<div class="page-header animate-in">
  <div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <h1 class="page-title">NAT64 / DNS64</h1>
      <span class="badge badge-success" style="font-size:0.7rem;">CLASS B</span>
      <span class="badge badge-muted" style="font-size:0.7rem;"><?= esc($plugin['id']) ?> · v<?= esc($plugin['version']) ?></span>
      <span id="n6StateBadge" class="badge badge-muted" style="font-size:0.7rem;">CHECKING…</span>
    </div>
    <p class="page-subtitle">
      IPv6-only clients reach IPv4-only hosts: tayga translates the packets (NAT64) and Unbound synthesises AAAA
      records so the names resolve (DNS64). Debian <code>tayga</code>, userspace — no kernel module, no third-party
      repository.
    </p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
    <button type="button" class="btn btn-ghost btn-sm" onclick="n6Refresh()">Refresh status</button>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:16px 18px; margin-bottom:16px;">
  <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
    <span class="n6-led" id="n6Led"></span>
    <div style="font-weight:700; font-size:0.92rem;">Translator status</div>
    <span id="n6StatusNote" style="font-size:.74rem; color:var(--text-muted);"></span>
  </div>

  <div class="n6-grid">
    <dl class="n6-meta" style="display:grid; grid-template-columns:1fr; gap:3px;">
      <dt>tayga service</dt><dd id="n6Tayga">—</dd>
      <dt>Host network unit</dt><dd id="n6Net">—</dd>
      <dt>NAT64 prefix</dt><dd id="n6Prefix">—</dd>
      <dt>Dynamic pool</dt><dd id="n6Pool">—</dd>
    </dl>
    <div>
      <dl class="n6-meta" style="display:grid; grid-template-columns:170px 1fr; gap:3px 12px;">
        <dt>DNS64 fragment</dt><dd id="n6Fragment">—</dd>
        <dt>Applied</dt><dd id="n6Applied">—</dd>
      </dl>
      <div id="n6Warnings" style="margin-top:10px; font-size:.72rem; color:#f59e0b;"></div>
      <div id="n6Steps" style="margin-top:8px; font-size:.7rem; color:var(--text-muted); font-family:var(--font-mono);"></div>
    </div>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px; margin-bottom:16px;">
  <div style="font-weight:700; font-size:0.92rem; margin-bottom:2px;">Configuration</div>
  <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:12px;">
    The prefix must be a /32, /40, /48, /56, /64 or /96 (RFC 6052). With the Well-Known Prefix
    <code>64:ff9b::/96</code> the pool must be globally routable — RFC 6052 forbids embedding private IPv4 in it;
    use a prefix from your own IPv6 space for a private pool. The DNS64 half is written beside the resolver and
    validated with <code>unbound-checkconf</code> before it is loaded; the resolver's own unit is never controlled
    from here. Applying also recompiles and reloads the firewall, so the translator's interface is permitted while
    the plugin is enabled and the hole closes when you uncheck “NAT64 enabled”.
  </div>
  <form id="n6Form" onsubmit="return n6Apply(event)">
    <div class="repo-grid">
      <div class="repo-field">
        <label for="n6PrefixInput">NAT64 prefix</label>
        <input type="text" id="n6PrefixInput" name="prefix" value="64:ff9b::/96" spellcheck="false"
               pattern="[0-9a-fA-F:]+/[0-9]{1,3}" required>
      </div>
      <div class="repo-field">
        <label for="n6PoolInput">IPv4 dynamic pool</label>
        <input type="text" id="n6PoolInput" name="pool" placeholder="192.168.255.0/24" spellcheck="false"
               pattern="[0-9.]+/[0-9]{1,2}" required>
        <div style="font-size:.68rem; color:var(--text-muted); margin-top:2px;">
          tayga assigns translated hosts an address from this pool (/30 or shorter).
        </div>
      </div>
      <div class="repo-field">
        <label for="n6Dns64" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="n6Dns64" name="dns64" checked>
          DNS64 synthesis (Unbound)
        </label>
        <div style="font-size:.68rem; color:var(--text-muted);">
          Synthesises AAAA records for IPv4-only names, using the same prefix.
        </div>
      </div>
      <div class="repo-field">
        <label for="n6Enabled" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="n6Enabled" name="enabled" checked>
          NAT64 enabled
        </label>
        <div style="font-size:.68rem; color:var(--text-muted);">
          Unchecking tears the translator down: unit stopped, host routes removed, fragment unloaded.
        </div>
      </div>
    </div>
    <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary btn-sm">Apply configuration</button>
    </div>
    <div id="n6FormNote" style="margin-top:10px; font-size:.74rem;"></div>
  </form>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px;">
  <div style="font-weight:700; font-size:0.9rem; margin-bottom:8px;">Diagnostics</div>
  <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:10px;">
    The translator's own service unit is controllable here, and its journal is available beside it.
  </div>
  <?= \App\Libraries\ServiceStatus::widget(['tayga'], ['tayga' => 'tayga NAT64']) ?>
</div>

<script>
const N6_STATUS_URL = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
const N6_APPLY_URL  = <?= json_encode($applyUrl, JSON_UNESCAPED_SLASHES) ?>;
let N6_CSRF = <?= json_encode(csrf_hash()) ?>;

function n6Led(el, on) {
  el.classList.remove('on', 'off');
  el.classList.add(on ? 'on' : 'off');
}

function n6Render(result) {
  const data = (result && result.data) || {};
  const badge = document.getElementById('n6StateBadge');
  const running = data.tayga_state === 'running';

  badge.textContent = running ? (data.enabled ? 'TRANSLATING' : 'STOPPED') : (data.tayga_conf_present ? 'STOPPED' : 'NOT APPLIED');
  badge.className = 'badge ' + (running && data.enabled ? 'badge-success' : (data.tayga_conf_present ? 'badge-warning' : 'badge-muted'));
  n6Led(document.getElementById('n6Led'), running && data.enabled !== false);

  const set = (id, value, fallback) => {
    document.getElementById(id).textContent = (value === undefined || value === null || value === '') ? (fallback || '—') : value;
  };
  set('n6Tayga', data.tayga_state, '—');
  set('n6Net', data.host_network_state, '—');
  set('n6Prefix', data.prefix, '—');
  set('n6Pool', data.pool, '—');
  set('n6Fragment', data.dns64_fragment_present ? 'present (loaded on reload)' : 'absent');
  set('n6Applied', data.applied_at, data.tayga_conf_present ? '—' : 'never');

  // Pre-fill the form from the applied state so a reload shows what is live.
  if (data.prefix) document.getElementById('n6PrefixInput').value = data.prefix;
  if (data.pool) document.getElementById('n6PoolInput').value = data.pool;
  if (data.dns64 !== undefined) document.getElementById('n6Dns64').checked = data.dns64 === true;
  if (data.enabled !== undefined) document.getElementById('n6Enabled').checked = data.enabled === true;

  const warnings = (result && result.warnings) || [];
  document.getElementById('n6Warnings').innerHTML = warnings.map(w => '⚠ ' + w).join('<br>');
  const steps = (result && result.steps) || [];
  document.getElementById('n6Steps').innerHTML = steps.map(s => '· ' + s).join('<br>');
}

async function n6Call(url, options) {
  const response = await MicronScheduler.fetch(url, options);
  return response.json();
}

async function n6Refresh() {
  const note = document.getElementById('n6StatusNote');
  note.textContent = 'reading…';
  try {
    const body = await n6Call(N6_STATUS_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (body.csrf) N6_CSRF = body.csrf;
    n6Render(body.result);
    note.textContent = body.ok ? '' : (body.message || 'the daemon rejected the request');
  } catch (e) {
    note.textContent = 'status unavailable';
  }
}

async function n6Apply(event) {
  event.preventDefault();
  const note = document.getElementById('n6StatusNote');
  const formNote = document.getElementById('n6FormNote');
  note.textContent = 'applying…';
  formNote.textContent = '';
  formNote.style.color = 'var(--text-muted)';

  const body = await n6Call(N6_APPLY_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': N6_CSRF },
    body: JSON.stringify({
      enabled: document.getElementById('n6Enabled').checked,
      prefix: document.getElementById('n6PrefixInput').value.trim(),
      pool: document.getElementById('n6PoolInput').value.trim(),
      dns64: document.getElementById('n6Dns64').checked,
    }),
  });
  if (body.csrf) N6_CSRF = body.csrf;

  formNote.textContent = body.ok ? (body.message || 'applied.') : ('failed: ' + (body.message || 'unknown error'));
  formNote.style.color = body.ok ? '#22c55e' : '#ef4444';
  note.textContent = body.ok ? '' : 'apply failed';
  if (body.result) n6Render(body.result);
  return false;
}

n6Refresh();
</script>

<?= $this->endSection() ?>
