<?php
/**
 * Service control widget — Start / Restart / Stop buttons for the daemon(s)
 * backing a page, mirroring the pfSense / OPNsense service controls.
 *
 * Usage (inside a page header, on the right):
 *   <?= $this->include('partials/service_controls', ['services' => 'squid']) ?>
 *   <?= $this->include('partials/service_controls', ['services' => ['keepalived', 'conntrackd']]) ?>
 *
 * The buttons post to `services/{id}/control` (JSON) and update in place, so
 * the page never navigates. The green Start button doubles as the run-state
 * indicator, exactly like the reference appliance UI.
 */
$__svcIds = $services ?? [];
if (is_string($__svcIds)) {
    $__svcIds = [$__svcIds];
}
$__svcIds = array_values(array_filter(array_map('strval', (array) $__svcIds), static fn ($s) => $s !== ''));
// Optional id => friendly display name. The id still drives every control call;
// only the visible name changes, so a capability can read "PIM" while the real
// daemon (frr) stays in data-service and the control endpoints.
$__labels   = (isset($labels) && is_array($labels)) ? $labels : [];
// Optional id => initial run-state. Used by callers whose ids are not rows in
// `system_services` (e.g. dynamic `dhcp-helper@<slug>` relay instances), so the
// LED is accurate on first paint instead of waiting for the first poll.
$__statuses = (isset($statuses) && is_array($statuses)) ? $statuses : [];
// Label shown for an "unknown" run-state. Callers whose ids are instance-scoped
// (relay helpers) use this to distinguish "not applied on the daemon" from a
// genuinely stopped service; everything else keeps a neutral "unknown".
$__unknownLabel = (string) ($unknownLabel ?? 'unknown');
// When set, an instance whose run-state is unknown (i.e. no unit exists on the
// daemon) gets its Start/Restart/Stop buttons disabled — the controls could
// only ever error. Opt-in: the relay widget turns it on for not-yet-applied
// instances; a plain daemon lookup leaves the buttons alone.
$__lockUnknown = ! empty($lockWhenUnknown);
// The JSON endpoint that drives both control POSTs (`{endpoint}/{id}/control`)
// and status polls (`{endpoint}/status?ids=...`). Defaults to the shared
// `services` daemon API; pages with instance-scoped controls override it.
$__svcEndpoint   = rtrim((string) ($endpoint ?? '') !== '' ? (string) $endpoint : base_url('services'), '/');
// Where the LED's log viewer fetches from (`{logsBase}/{id}`). Empty disables
// the click-through so a page without a log source does not show a dead link.
$__logsBase      = (string) ($logsBase ?? base_url('services/logs'));
$__svcCsrfHeader = csrf_header();
$__svcCsrfHash   = csrf_hash();
?>
<?php if (!empty($__svcIds)): ?>
<div class="mno-svc-wrap" data-endpoint="<?= esc($__svcEndpoint) ?>" data-unknown="<?= esc($__unknownLabel) ?>" data-lock-unknown="<?= $__lockUnknown ? '1' : '0' ?>"<?php if ($__logsBase !== ''): ?> data-logs="<?= esc(rtrim($__logsBase, '/')) ?>"<?php endif; ?> data-poll="15000">
  <div class="mno-svc-bar">
    <?php foreach ($__svcIds as $__id): ?>
      <?php
        $__svc     = \App\Libraries\ServiceStatus::get($__id);
        $__status  = (string) ($__statuses[$__id] ?? $__svc['status']);
        $__running = $__status === 'running';
        $__stateText = match ($__status) {
            'running' => 'running',
            'failed'  => 'failed',
            'stopped' => 'stopped',
            default   => $__unknownLabel,
        };
        $__label   = (string) ($__labels[$__id] ?? '');
        $__daemon  = (string) (($__label !== '' && ! empty($__statuses)) ? $__id : $__svc['name']);
        $__name    = $__label !== '' ? $__label : $__daemon;
        $__title   = $__name === $__daemon
            ? "Daemon {$__daemon}"
            : "Daemon {$__daemon} ({$__name})";
        // Locked = no unit on the daemon yet, so every action would error.
        $__locked  = $__lockUnknown && ! in_array($__status, ['running', 'stopped', 'failed'], true);
        $__lockNote = $__locked ? ' — not applied on the daemon yet' : '';
        $__lockAttr = $__locked ? ' disabled aria-disabled="true"' : '';
      ?>
      <div class="mno-svc<?= $__locked ? ' is-locked' : '' ?>" data-service="<?= esc($__id) ?>" data-status="<?= esc($__status) ?>"
           data-name="<?= esc($__name) ?>"
           title="<?= esc($__title) ?> — currently <?= esc($__status) ?><?= esc($__lockNote) ?>">
        <span class="mno-svc-meta"<?php if ($__logsBase !== ''): ?> data-action="logs" role="button" tabindex="0"
              title="View recent <?= esc($__name) ?> logs"<?php endif; ?>>
          <span class="mno-svc-dot" aria-hidden="true"></span>
          <span class="mno-svc-name"><?= esc($__name) ?></span>
          <span class="mno-svc-state"><?= esc($__stateText) ?></span>
        </span>
        <span class="mno-svc-btns">
          <button type="button" class="mno-svc-btn mno-svc-toggle" aria-label="Show daemon details" aria-expanded="false"
                  title="Show name & state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </button>
          <button type="button" class="mno-svc-btn mno-svc-start" data-action="start"<?= $__lockAttr ?>
                  title="Start <?= esc($__name) ?><?= esc($__lockNote) ?>" aria-label="Start <?= esc($__name) ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="6 4 20 12 6 20 6 4"/></svg>
          </button>
          <button type="button" class="mno-svc-btn mno-svc-restart" data-action="restart"<?= $__lockAttr ?>
                  title="Restart <?= esc($__name) ?><?= esc($__lockNote) ?>" aria-label="Restart <?= esc($__name) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
          </button>
          <button type="button" class="mno-svc-btn mno-svc-stop" data-action="stop"<?= $__lockAttr ?>
                  title="Stop <?= esc($__name) ?><?= esc($__lockNote) ?>" aria-label="Stop <?= esc($__name) ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="5" y="5" width="14" height="14" rx="1.5"/></svg>
          </button>
        </span>
      </div>
    <?php endforeach; ?>
  </div>  <div class="mno-svc-msg" role="status" aria-live="polite"></div>
</div>
<?= $this->include('partials/service_logs_modal') ?>
<style>
/* order keeps the controls at the far right of the header action cluster,
   after the page's own action buttons, on every page. */
.mno-svc-wrap { display:inline-flex; flex-direction:column; gap:4px; align-items:flex-end; order:999; margin-left:auto; }
/* Header action row: give the cluster its own full-width row so the page's
   action buttons sit at the left and the service controls at the right. */
.page-header > div > div:has(> .mno-svc-wrap) { flex:1 1 100%; width:100%; margin-left:0; }
.mno-svc-bar { display:flex; flex-direction:column; gap:5px; }
.mno-svc {
  display:flex; align-items:center; gap:8px;
  background:var(--color-surface-2, #161616);
  border:1px solid var(--color-border, #333);
  border-radius:var(--radius-sm, 6px);
  padding:3px 4px 3px 10px;
  transition:border-color var(--transition-fast, .15s), opacity .15s;
}
.mno-svc.is-busy { opacity:.55; }
.mno-svc.is-busy .mno-svc-btn { pointer-events:none; }
/* A not-applied instance: its unit does not exist, so the action buttons are
   disabled rather than offering a control that can only error. */
.mno-svc.is-locked .mno-svc-btn[data-action] { opacity:.4; cursor:not-allowed; }
.mno-svc.is-locked .mno-svc-btn[data-action]:hover { background:rgba(255,255,255,.05); color:var(--text-secondary, #aaa); border-color:var(--color-border, #333); }
.mno-svc-meta { display:flex; align-items:center; gap:6px; font-size:.7rem; font-family:var(--font-mono, monospace); color:var(--text-secondary, #aaa); cursor:pointer; border-radius:4px; padding:2px 4px; transition:background var(--transition-fast, .15s), color var(--transition-fast, .15s); }
.mno-svc-meta:hover, .mno-svc-meta:focus-visible { background:rgba(255,255,255,.07); color:var(--text-primary, #fff); outline:none; }
.mno-svc[data-status="stopped"] .mno-svc-state, .mno-svc[data-status="failed"] .mno-svc-state { color:#ef4444; }
.mno-svc-name { text-transform:none; max-width:16ch; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.mno-svc-wrap, .mno-svc-bar { max-width:100%; }
.mno-svc-state { color:var(--text-muted, #777); font-size:.62rem; text-transform:uppercase; letter-spacing:.05em; }
.mno-svc-dot { width:8px; height:8px; border-radius:50%; background:#6b7280; flex-shrink:0; }
.mno-svc[data-status="running"] .mno-svc-dot { background:#10b981; box-shadow:0 0 0 3px rgba(16,185,129,.18); }
.mno-svc[data-status="stopped"] .mno-svc-dot { background:#ef4444; box-shadow:0 0 0 3px rgba(239,68,68,.16); }
.mno-svc[data-status="failed"]  .mno-svc-dot { background:#f59e0b; box-shadow:0 0 0 3px rgba(245,158,11,.18); }
.mno-svc-btns { display:inline-flex; gap:3px; }
.mno-svc-btn {
  width:32px; height:28px; border-radius:var(--radius-sm, 6px);
  display:inline-flex; align-items:center; justify-content:center;
  background:rgba(255,255,255,.05);
  border:1px solid var(--color-border, #333);
  color:var(--text-secondary, #aaa);
  cursor:pointer; transition:all var(--transition-fast, .15s);
}
.mno-svc-btn svg { width:13px; height:13px; }
.mno-svc-toggle { display:none; }
.mno-svc-toggle svg { transition:transform var(--transition-fast, .15s); }
.mno-svc.mno-svc-open .mno-svc-toggle svg { transform:rotate(180deg); }
/* Reveal the full name & state on hover, keyboard focus, or tap-expand even
   while the control is collapsed at narrow widths. */
.mno-svc:hover .mno-svc-name,
.mno-svc:focus-within .mno-svc-name,
.mno-svc.mno-svc-open .mno-svc-name { display:inline; max-width:none; }
.mno-svc:hover .mno-svc-state,
.mno-svc:focus-within .mno-svc-state,
.mno-svc.mno-svc-open .mno-svc-state { display:inline; }
.mno-svc:hover .mno-svc-meta,
.mno-svc:focus-within .mno-svc-meta,
.mno-svc.mno-svc-open .mno-svc-meta { white-space:nowrap; }
.mno-svc-btn:hover { background:rgba(255,255,255,.12); color:var(--text-primary, #fff); }
.mno-svc-start:hover { color:#10b981; border-color:#10b981; }
.mno-svc-restart:hover { color:var(--color-cyan, #06b6d4); border-color:var(--color-cyan, #06b6d4); }
.mno-svc-stop:hover { color:#ef4444; border-color:#ef4444; }
/* Running: the Start button becomes a filled green indicator (pfSense style) */
.mno-svc[data-status="running"] .mno-svc-start {
  background:#4caf50; border-color:#4caf50; color:#fff;
  box-shadow:0 1px 6px rgba(76,175,80,.35);
}
.mno-svc[data-status="running"] .mno-svc-start:hover { background:#43a047; color:#fff; }
.mno-svc-msg { min-height:0; font-size:.68rem; color:var(--text-muted, #888); text-align:right; transition:opacity .2s; }
.mno-svc-msg.ok { color:#10b981; }
.mno-svc-msg.err { color:#ef4444; }
/* Compact, single-line collapse: drop the state word first, then tighten, then
   hide the daemon name — so the controls never dominate or wrap awkwardly. */
@media (max-width: 1400px) { .mno-svc-state { display:none; } .mno-svc-toggle { display:inline-flex; } }
@media (max-width: 1100px) {
  .mno-svc { gap:5px; padding:3px 3px 3px 8px; }
  .mno-svc-meta { gap:4px; }
  .mno-svc-name { max-width:12ch; }
  .mno-svc-btn { width:28px; height:26px; }
}
@media (max-width: 1150px) {
  .mno-svc-name { max-width:9ch; }
}
@media (max-width: 640px) {
  .mno-svc-name { display:none; }
  .mno-svc { padding:3px 3px 3px 7px; }
  .mno-svc-btn { width:26px; height:26px; }
  .mno-svc-btn svg { width:12px; height:12px; }
}
</style>
<script>
(function () {
  if (window.__mnoSvcWidget) { return; }
  window.__mnoSvcWidget = true;

  var CSRF_HEADER = <?= json_encode($__svcCsrfHeader) ?>;
  var CSRF_HASH   = <?= json_encode($__svcCsrfHash) ?>;

  function applyState(box, status) {
    box.dataset.status = status;
    var running = status === 'running';
    box.classList.toggle('is-running', running);

    // Mirror the server-rendered lock: while an instance has no unit on the
    // daemon (unknown state), its action buttons stay disabled.
    var lockWrap = box.closest('.mno-svc-wrap');
    if (lockWrap && lockWrap.dataset.lockUnknown === '1') {
      var locked = status !== 'running' && status !== 'stopped' && status !== 'failed';
      box.classList.toggle('is-locked', locked);
      box.querySelectorAll('.mno-svc-btn[data-action]').forEach(function (b) {
        b.disabled = locked;
        b.setAttribute('aria-disabled', locked ? 'true' : 'false');
      });
    }

    var state = box.querySelector('.mno-svc-state');
    if (state) {
      var wrap    = box.closest('.mno-svc-wrap');
      var unknown = (wrap && wrap.dataset.unknown) ? wrap.dataset.unknown : 'unknown';
      state.textContent = status === 'running' ? 'running'
        : status === 'failed' ? 'failed'
        : status === 'stopped' ? 'stopped'
        : unknown;
    }
    var label = box.dataset.name || box.dataset.service;
    var title = (label === box.dataset.service)
      ? ('Daemon ' + box.dataset.service)
      : ('Daemon ' + box.dataset.service + ' (' + label + ')');
    box.title = title + ' — currently ' + status;
  }

  function flash(wrap, text, ok) {
    var msg = wrap.querySelector('.mno-svc-msg');
    if (!msg) { return; }
    msg.textContent = text;
    msg.className = 'mno-svc-msg ' + (ok ? 'ok' : 'err');
    clearTimeout(msg.__t);
    msg.__t = setTimeout(function () { msg.textContent = ''; }, 4000);
  }

  // The status indicator opens the daemon's recent logs (one click from a
  // red/failed LED to the journal).
  function openLogs(meta) {
    var box  = meta.closest('.mno-svc');
    var wrap = meta.closest('.mno-svc-wrap');
    if (!box || !wrap || !wrap.dataset.logs) { return; }
    var name = box.querySelector('.mno-svc-name');
    if (window.openServiceLogModal) {
      window.openServiceLogModal(box.dataset.service, name ? name.textContent : box.dataset.service, wrap.dataset.logs);
    }
  }

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') { return; }
    var meta = e.target.closest ? e.target.closest('.mno-svc-meta[data-action="logs"]') : null;
    if (meta) { e.preventDefault(); openLogs(meta); }
  });

  document.addEventListener('click', function (e) {
    // Expand/collapse the compact control to reveal the full name & state.
    var tog = e.target.closest ? e.target.closest('.mno-svc-toggle') : null;
    if (tog) {
      var box  = tog.closest('.mno-svc');
      var open = !box.classList.contains('mno-svc-open');
      document.querySelectorAll('.mno-svc.mno-svc-open').forEach(function (b) { b.classList.remove('mno-svc-open'); });
      box.classList.toggle('mno-svc-open', open);
      tog.setAttribute('aria-expanded', open ? 'true' : 'false');
      return;
    }
    // Any click outside a control closes an expanded one.
    if (!(e.target.closest && e.target.closest('.mno-svc'))) {
      document.querySelectorAll('.mno-svc.mno-svc-open').forEach(function (b) {
        b.classList.remove('mno-svc-open');
        var t = b.querySelector('.mno-svc-toggle');
        if (t) { t.setAttribute('aria-expanded', 'false'); }
      });
    }

    var meta = e.target.closest ? e.target.closest('.mno-svc-meta[data-action="logs"]') : null;
    if (meta) { openLogs(meta); return; }

    var btn = e.target.closest ? e.target.closest('.mno-svc-btn') : null;
    if (!btn || btn.disabled) { return; }
    var box  = btn.closest('.mno-svc');
    var wrap = btn.closest('.mno-svc-wrap');
    if (!box || !wrap) { return; }

    var action = btn.getAttribute('data-action');
    var id     = box.dataset.service;

    if (action === 'stop' && !confirm('Stop the ' + id + ' service? Network services depending on it may be interrupted.')) {
      return;
    }

    box.classList.add('is-busy');
    MicronScheduler.fetch(wrap.dataset.endpoint + '/' + encodeURIComponent(id) + '/control', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF_HASH },
      body: JSON.stringify({ action: action })
    })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'HTTP ' + r.status }; }); })
      .then(function (res) {
        box.classList.remove('is-busy');
        if (res && res.csrf) { CSRF_HASH = res.csrf; }
        if (res && res.status) { applyState(box, res.status); }
        flash(wrap, (res && res.message) ? res.message : (res && res.ok ? 'Done' : 'Action failed'), !!(res && res.ok));
      })
      .catch(function () {
        box.classList.remove('is-busy');
        flash(wrap, 'Request failed', false);
      });
  });

  // ---- Live status polling -------------------------------------------
  // Keep the run-state LED fresh without a page reload. One request refreshes
  // every daemon on the page, and polling pauses while the tab is hidden.
  function refresh(wrap) {
    var boxes = wrap.querySelectorAll('.mno-svc');
    if (!boxes.length) { return Promise.resolve(true); }
    var ids = [];
    boxes.forEach(function (b) { ids.push(b.dataset.service); });

    return MicronScheduler.fetch(wrap.dataset.endpoint + '/status?ids=' + encodeURIComponent(ids.join(',')), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.services) { return true; }
        boxes.forEach(function (box) {
          var status = res.services[box.dataset.service];
          if (status && !box.classList.contains('is-busy') && box.dataset.status !== status) {
            applyState(box, status);
          }
        });
        return true;
      }, function () { return false; });   // a failed attempt: the scheduler backs the poll off
  }

  // The shared scheduler owns the cadence — it holds the poll while the tab is
  // hidden and wakes it on `visibilitychange` itself — so this widget needs
  // neither its own timer nor its own visibility handler.
  document.querySelectorAll('.mno-svc-wrap').forEach(function (wrap, index) {
    var interval = parseInt(wrap.dataset.poll || '0', 10);
    if (!interval || interval <= 0) { return; }

    MicronScheduler.poll('service.' + index, {
      interval: interval,
      run: function () { return refresh(wrap); }
    });
  });
})();
</script>
<?php endif; ?>
