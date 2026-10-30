<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
$__v = static fn (string $f): string => base_url('assets/vendor/xterm/' . $f) . '?v=' . (@filemtime(FCPATH . 'assets/vendor/xterm/' . $f) ?: 1);
?>
<link rel="stylesheet" href="<?= $__v('xterm.css') ?>">

<div class="page-header animate-in" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
  <div>
    <h1 class="page-title" style="display:flex; align-items:center; gap:10px;">
      <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
      Terminal
    </h1>
    <p class="page-subtitle">An interactive shell on this appliance, in the browser. Standard terminals run as the unprivileged <code>micron-term</code> account; root terminals need the <code>terminal.admin</code> permission and your password.</p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <button type="button" class="btn btn-primary" id="termNewStd" <?= ($available && $transport === null) ? '' : 'disabled' ?>>+ New terminal</button>
    <?php if ($canAdmin): ?>
      <button type="button" class="btn btn-danger" id="termNewAdmin" <?= ($available && $transport === null) ? '' : 'disabled' ?> title="Root shell — every command runs with full privileges">+ Root terminal</button>
    <?php endif; ?>
  </div>
</div>

<?php if (! $available): ?>
  <div class="alert alert-warning" role="alert">
    <span class="alert-text">The terminal service (<code>micron-terminald</code>) is not running on this system, so no shell can be opened.
    On an appliance: <code>systemctl status micron-terminald</code>. The rest of the Web UI and the network are not affected.</span>
  </div>
<?php elseif ($transport !== null): ?>
  <div class="alert alert-warning" role="alert"><span class="alert-text"><?= esc($transport) ?></span></div>
<?php endif; ?>

<div class="term-shell card" id="termShell">
  <div class="term-tabs" id="termTabs" role="tablist" aria-label="Terminal sessions">
    <span class="term-empty" id="termEmpty">No terminal open. <?= $available ? 'Use “New terminal” to start one.' : '' ?></span>
  </div>
  <div class="term-panes" id="termPanes"></div>
  <div class="term-status" id="termStatus" aria-live="polite">
    <span class="term-dot" id="termDot"></span>
    <span id="termState">idle</span>
    <span class="term-sep">·</span>
    <span id="termInfo">—</span>
    <span style="margin-left:auto; display:flex; gap:6px;">
      <button type="button" class="btn btn-ghost btn-sm" id="termEnd" disabled>End session</button>
    </span>
  </div>
</div>

<?php if ($available && $sessions !== []): ?>
<div class="card" style="margin-top:16px; padding:14px 18px;">
  <div style="font-weight:700; margin-bottom:8px;">Your sessions on this appliance</div>
  <table class="wd-table" id="termSessionList" style="width:100%; font-size:.8rem;">
    <?php foreach ($sessions as $s): ?>
      <tr data-session="<?= esc($s['id']) ?>">
        <td><code><?= esc($s['id']) ?></code></td>
        <td><?= $s['level'] === 'admin' ? '<span class="badge badge-danger">ROOT</span>' : '<span class="badge badge-muted">standard</span>' ?></td>
        <td class="wd-muted">started <?= esc(date('H:i', (int) $s['created'])) ?> · <?= $s['attached'] ? 'open in a window' : 'detached' ?></td>
        <td style="text-align:right;">
          <button type="button" class="btn btn-ghost btn-sm" data-attach="<?= esc($s['id']) ?>" data-level="<?= esc($s['level']) ?>">Reattach</button>
          <button type="button" class="btn btn-ghost btn-sm" data-kill="<?= esc($s['id']) ?>">End</button>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php if ($available && $status): ?>
<p style="font-size:.74rem; color:var(--text-muted); margin-top:12px;">
  Limits: <?= (int) $status['limits']['max_sessions_per_user'] ?> sessions per user ·
  idle timeout <?= (int) round($status['limits']['idle_timeout'] / 60) ?> min (root <?= (int) round($status['limits']['admin_idle_timeout'] / 60) ?> min) ·
  root sessions end after <?= (int) round($status['limits']['admin_max_lifetime'] / 60) ?> min ·
  a closed tab can be reattached for <?= (int) $status['limits']['detach_grace'] ?> s.
  Sessions are audited (who, when, level); what you type is not recorded.
</p>
<?php endif; ?>

<!-- Re-authentication for root terminals -->
<div class="term-modal" id="termReauth" role="dialog" aria-modal="true" aria-labelledby="termReauthTitle" hidden>
  <form class="term-modal-box card" id="termReauthForm">
    <h2 id="termReauthTitle" style="font-size:1.05rem; margin:0 0 6px;">Open a root terminal</h2>
    <p style="font-size:.8rem; color:var(--text-muted); margin:0 0 12px;">Every command in this shell runs as <strong>root</strong>. Re-enter your password to continue.</p>
    <input type="password" class="form-control" id="termReauthPw" autocomplete="current-password" required aria-label="Password">
    <div id="termReauthErr" style="color:#ef4444; font-size:.78rem; min-height:1.2em; margin-top:6px;"></div>
    <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
      <button type="button" class="btn btn-ghost btn-sm" id="termReauthCancel">Cancel</button>
      <button type="submit" class="btn btn-danger btn-sm">Unlock</button>
    </div>
  </form>
</div>

<style>
.term-shell { padding: 0; overflow: hidden; display: flex; flex-direction: column; min-height: 520px; }
.term-tabs { display: flex; gap: 2px; padding: 6px 8px 0; border-bottom: 1px solid var(--color-border); overflow-x: auto; min-height: 38px; align-items: flex-end; }
.term-tab { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; font-size: .78rem; font-family: var(--font-mono);
  border: 1px solid var(--color-border); border-bottom: none; border-radius: 6px 6px 0 0; background: transparent; color: var(--text-secondary); cursor: pointer; }
.term-tab[aria-selected="true"] { background: #0b0f14; color: #e5e7eb; }
.term-tab.root { border-color: #ef4444; }
.term-tab.root::before { content: "ROOT"; font-size: .6rem; font-weight: 800; color: #fff; background: #dc2626; padding: 1px 4px; border-radius: 3px; }
.term-tab .term-x { opacity: .6; } .term-tab .term-x:hover { opacity: 1; }
.term-empty { font-size: .8rem; color: var(--text-muted); padding: 8px; }
.term-panes { flex: 1; position: relative; background: #0b0f14; min-height: 440px; }
.term-pane { position: absolute; inset: 0; padding: 6px 8px; display: none; }
.term-pane.active { display: block; }
.term-pane.root { box-shadow: inset 0 3px 0 #dc2626; }
.term-pane .xterm-cursor.xterm-cursor-blink { animation-duration: 2s !important; }
.term-pane .xterm-viewport {
  overflow-y: scroll !important;
  scrollbar-width: thin;
  scrollbar-color: #64748b #111820;
}
.term-pane .xterm-viewport::-webkit-scrollbar { width: 10px; }
.term-pane .xterm-viewport::-webkit-scrollbar-track { background: #111820; }
.term-pane .xterm-viewport::-webkit-scrollbar-thumb { background: #64748b; border-radius: 5px; }
.term-pane .xterm-viewport::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
.term-status { display: flex; align-items: center; gap: 8px; padding: 6px 12px; font-size: .75rem; font-family: var(--font-mono);
  border-top: 1px solid var(--color-border); color: var(--text-muted); }
.term-dot { width: 8px; height: 8px; border-radius: 50%; background: #64748b; }
.term-dot.ok { background: #10b981; } .term-dot.warn { background: #f59e0b; } .term-dot.err { background: #ef4444; }
.term-modal { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; z-index: 10000; }
.term-modal[hidden] { display: none; }
.term-modal-box { width: min(420px, calc(100vw - 32px)); padding: 18px; }
@media (max-width: 640px) { .term-shell { min-height: 420px; } .term-panes { min-height: 340px; } }
</style>

<script src="<?= $__v('xterm.js') ?>"></script>
<script src="<?= $__v('addon-fit.js') ?>"></script>
<script>
(function () {
  'use strict';
  const CSRF_NAME = <?= json_encode(csrf_token()) ?>;
  let csrf = <?= json_encode(csrf_hash()) ?>;
  const URLS = {
    session: <?= json_encode(base_url('terminal/session')) ?>,
    elevate: <?= json_encode(base_url('terminal/elevate')) ?>,
    kill: <?= json_encode(base_url('terminal/sessions')) ?>,
  };
  const tabs = document.getElementById('termTabs');
  const panes = document.getElementById('termPanes');
  const empty = document.getElementById('termEmpty');
  const stateEl = document.getElementById('termState');
  const infoEl = document.getElementById('termInfo');
  const dot = document.getElementById('termDot');
  const endBtn = document.getElementById('termEnd');
  const views = [];   // {key, level, session, term, fit, ws, tab, pane, state, retries, ended}
  let active = null;

  async function post(url, data) {
    const fd = new FormData();
    Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
    fd.append(CSRF_NAME, csrf);
    const res = await (window.MicronScheduler ? MicronScheduler.fetch : fetch)(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' });
    let body = {};
    try { body = await res.json(); } catch (e) { body = { ok: false, message: 'Unexpected response (' + res.status + ')' }; }
    if (body && typeof body.csrf === 'string' && body.csrf) csrf = body.csrf;
    body.status = res.status;
    return body;
  }

  function setState(v, state, cls, info) {
    v.state = state;
    if (v !== active) return;
    stateEl.textContent = state;
    dot.className = 'term-dot ' + (cls || '');
    infoEl.textContent = info || (v.session ? (v.level === 'admin' ? 'ROOT · ' : 'standard · ') + 'session ' + v.session + ' · ' + v.term.cols + '×' + v.term.rows : '—');
    endBtn.disabled = !v.session || v.ended;
  }

  function select(v) {
    active = v;
    views.forEach(x => { x.tab.setAttribute('aria-selected', String(x === v)); x.pane.classList.toggle('active', x === v); });
    if (v) { requestAnimationFrame(() => { try { v.fit.fit(); } catch (e) {} v.term.focus(); }); setState(v, v.state, v.ended ? 'err' : (v.ws && v.ws.readyState === 1 ? 'ok' : 'warn')); }
    else { stateEl.textContent = 'idle'; dot.className = 'term-dot'; infoEl.textContent = '—'; endBtn.disabled = true; }
    empty.style.display = views.length ? 'none' : '';
  }

  function makeView(level, session) {
    const key = Math.random().toString(36).slice(2);
    const tab = document.createElement('button');
    tab.type = 'button'; tab.className = 'term-tab' + (level === 'admin' ? ' root' : ''); tab.setAttribute('role', 'tab');
    tab.innerHTML = '<span class="term-name"></span><span class="term-x" aria-label="Close tab" title="Close tab (the session stays open for reattach)">✕</span>';
    tabs.appendChild(tab);
    const pane = document.createElement('div');
    pane.className = 'term-pane' + (level === 'admin' ? ' root' : ''); pane.setAttribute('role', 'tabpanel');
    panes.appendChild(pane);
    const term = new Terminal({
      cursorBlink: true, fontSize: 13, scrollback: 5000, convertEol: false,
      fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
      theme: level === 'admin'
        ? { background: '#140a0a', foreground: '#f3f4f6', cursor: '#f87171' }
        : { background: '#0b0f14', foreground: '#e5e7eb', cursor: '#fbbf24' },
    });
    const fit = new FitAddon.FitAddon();
    term.loadAddon(fit);
    term.open(pane);
    const v = { key, level, session: session || null, term, fit, ws: null, tab, pane, state: 'connecting', retries: 0, ended: false };
    tab.querySelector('.term-name').textContent = (session || 'new');
    tab.addEventListener('click', e => { if (e.target.classList.contains('term-x')) { closeTab(v); } else { select(v); } });
    term.onData(d => { if (v.ws && v.ws.readyState === 1) v.ws.send(JSON.stringify({ t: 'i', d })); });
    term.onResize(({ cols, rows }) => { if (v.ws && v.ws.readyState === 1) v.ws.send(JSON.stringify({ t: 'r', c: cols, r: rows })); if (v === active) setState(v, v.state, 'ok'); });
    views.push(v);
    select(v);
    return v;
  }

  async function connect(v) {
    setState(v, v.session ? 'reconnecting…' : 'connecting…', 'warn');
    const r = await post(URLS.session, v.session ? { level: v.level, attach: v.session } : { level: v.level });
    if (!r.ok) {
      if (r.reauth) { const ok = await reauth(); if (ok) return connect(v); }
      v.ended = true;
      v.term.write('\r\n\x1b[31m' + (r.message || 'The terminal could not be opened.') + '\x1b[0m\r\n');
      setState(v, 'refused', 'err', r.message || 'refused');
      return;
    }
    const sep = r.ws_url.includes('?') ? '&' : '?';
    const ws = new WebSocket(r.ws_url + sep + 'ticket=' + encodeURIComponent(r.ticket));
    ws.binaryType = 'arraybuffer';
    v.ws = ws;
    let closedReason = null;
    ws.onopen = () => { v.retries = 0; };
    ws.onmessage = ev => {
      if (typeof ev.data !== 'string') { v.term.write(new Uint8Array(ev.data)); return; }
      let m; try { m = JSON.parse(ev.data); } catch (e) { return; }
      if (m.t === 'hello') {
        v.session = m.session;
        v.tab.querySelector('.term-name').textContent = m.session;
        try { v.fit.fit(); } catch (e) {}
        ws.send(JSON.stringify({ t: 'r', c: v.term.cols, r: v.term.rows }));
        setState(v, m.reattached ? 'connected (reattached)' : 'connected', 'ok');
        if (v === active) v.term.focus();
        if (m.level === 'admin' && !m.reattached) v.term.write('\x1b[1;31m*** ROOT SHELL — every command runs with full privileges. Session ends after ' + Math.round(m.max_lifetime / 60) + ' min. ***\x1b[0m\r\n');
      } else if (m.t === 'exit') {
        v.term.write('\r\n\x1b[33m[shell exited' + (m.code !== null && m.code !== undefined ? ' with status ' + m.code : '') + ']\x1b[0m\r\n');
      } else if (m.t === 'closed') {
        closedReason = m.reason || 'closed';
      } else if (m.t === 'notice') {
        console.warn('[terminal]', m.msg);
      }
    };
    ws.onclose = ev => {
      if (v.ws !== ws) return;
      v.ws = null;
      if (closedReason) {
        v.ended = true;
        v.term.write('\r\n\x1b[33m[session ended: ' + closedReason + ']\x1b[0m\r\n');
        setState(v, 'ended', 'err', closedReason);
        return;
      }
      if (v.ended) return;
      // Network drop: reattach to the same session (never a new one).
      if (v.session && v.retries < 3) {
        v.retries++;
        setState(v, 'disconnected — retrying (' + v.retries + '/3)…', 'warn');
        setTimeout(() => connect(v), 1500 * v.retries);
      } else {
        v.ended = !v.session;
        setState(v, 'disconnected', 'err', v.session ? 'Connection lost. Reattach from the session list.' : 'Connection failed.');
      }
    };
  }

  function reauth() {
    return new Promise(resolve => {
      const modal = document.getElementById('termReauth');
      const form = document.getElementById('termReauthForm');
      const pw = document.getElementById('termReauthPw');
      const err = document.getElementById('termReauthErr');
      err.textContent = ''; pw.value = ''; modal.hidden = false; pw.focus();
      const done = ok => { modal.hidden = true; form.onsubmit = null; document.getElementById('termReauthCancel').onclick = null; resolve(ok); };
      document.getElementById('termReauthCancel').onclick = () => done(false);
      form.onsubmit = async e => {
        e.preventDefault();
        const r = await post(URLS.elevate, { password: pw.value });
        pw.value = '';
        if (r.ok) done(true); else err.textContent = r.message || 'Refused.';
      };
    });
  }

  async function closeTab(v) {
    if (v.ws) { const ws = v.ws; v.ws = null; ws.close(); }
    v.term.dispose(); v.tab.remove(); v.pane.remove();
    views.splice(views.indexOf(v), 1);
    select(views[views.length - 1] || null);
  }

  async function endSession(v) {
    if (!v || !v.session) return;
    if (v.ws && v.ws.readyState === 1) { v.ws.send(JSON.stringify({ t: 'x' })); return; }
    const r = await post(URLS.kill + '/' + encodeURIComponent(v.session) + '/kill', {});
    v.ended = true;
    setState(v, 'ended', 'err', r.ok ? 'terminated' : (r.message || 'not found'));
  }

  function open(level, session) {
    const existing = session && views.find(x => x.session === session && !x.ended);
    if (existing) { select(existing); return; }
    connect(makeView(level, session));
  }

  const btnStd = document.getElementById('termNewStd');
  if (btnStd) btnStd.addEventListener('click', () => open('standard'));
  const btnAdm = document.getElementById('termNewAdmin');
  if (btnAdm) btnAdm.addEventListener('click', () => open('admin'));
  endBtn.addEventListener('click', () => endSession(active));
  document.querySelectorAll('[data-attach]').forEach(b => b.addEventListener('click', () => open(b.dataset.level, b.dataset.attach)));
  document.querySelectorAll('[data-kill]').forEach(b => b.addEventListener('click', async () => {
    const r = await post(URLS.kill + '/' + encodeURIComponent(b.dataset.kill) + '/kill', {});
    if (r.ok) { const row = b.closest('tr'); if (row) row.remove(); }
  }));
  window.addEventListener('resize', () => { if (active) { try { active.fit.fit(); } catch (e) {} } });
  if (window.ResizeObserver) new ResizeObserver(() => { if (active) { try { active.fit.fit(); } catch (e) {} } }).observe(panes);
  window.MicronTerminal = { views, open };
})();
</script>
<?= $this->endSection() ?>
