<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.frr-banner {
  display: flex; align-items: center; gap: 14px;
  background: linear-gradient(135deg, rgba(6,182,212,0.08), rgba(139,92,246,0.08));
  border: 1px solid rgba(6,182,212,0.25);
  border-radius: var(--radius-lg); padding: 12px 20px; margin-bottom: 20px; flex-wrap: wrap;
}
.frr-badge { display: flex; align-items: center; gap: 6px; font-size: 0.78rem; color: var(--text-secondary); }
.frr-badge strong { color: var(--text-primary); }
.frr-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--color-emerald); box-shadow: 0 0 6px var(--color-emerald); }

.frr-form { border:1px solid var(--color-border); border-radius:var(--radius-md); overflow:hidden; margin-bottom:20px; background:var(--color-surface-1); }
.frr-section-hdr {
  background: rgba(34,211,238,0.1);
  border-bottom: 1px solid var(--color-border);
  padding: 8px 18px; font-size: 0.72rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-cyan-2);
}
.frr-row { display: grid; grid-template-columns: 200px 1fr; border-bottom: 1px solid var(--color-border); min-height: 46px; }
.frr-row:last-child { border-bottom: none; }
.frr-lbl { padding: 11px 18px; font-size: 0.82rem; font-weight: 600; color: var(--text-secondary); border-right: 1px solid var(--color-border); display: flex; align-items: flex-start; flex-direction: column; justify-content: center; gap: 2px; background: rgba(255,255,255,0.015); }
.frr-lbl .sub { font-size: 0.68rem; font-weight: 400; color: var(--text-muted); }
.frr-val { padding: 9px 18px; display: flex; flex-direction: column; gap: 4px; }
.frr-val .hint { font-size: 0.71rem; color: var(--text-muted); line-height: 1.4; }
.frr-input { background: var(--color-surface-3); border: 1px solid var(--color-border); color: var(--text-primary); padding: 6px 9px; border-radius: var(--radius-sm); font-size: 0.85rem; max-width: 400px; width: 100%; }
.frr-input:focus { outline: none; border-color: var(--color-cyan-2); }
.frr-input-sm { max-width: 140px; }
.frr-input-md { max-width: 260px; }
.frr-input-mono { font-family: var(--font-mono); }
.frr-select { max-width: 320px; }
.frr-chk { display: flex; align-items: center; gap: 8px; font-size: 0.83rem; color: var(--text-secondary); margin-top: 2px; }
.frr-chk input { accent-color: var(--color-cyan-2); width: 16px; height: 16px; }
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <span>Network</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>Routing Engine</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>BFD</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:var(--tone-cyan-2-bg); color:var(--tone-cyan-2-fg); border:1px solid var(--tone-cyan-2-border);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
      </div>
      <div>
        <h1 class="page-title">BFD (Bidirectional Forwarding Detection)</h1>
        <p class="page-subtitle">Configure BFD peers for sub-second link failure detection with BGP, OSPF, and IS-IS</p>
      </div>
    </div>
  </div>
  <div class="page-header-actions">
    <a href="<?= base_url('routing') ?>" class="btn btn-secondary btn-sm">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
      Back to Routing
    </a>
  </div>
</div>

<!-- ── Flash Messages ── -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<!-- ── FRR Status Banner ── -->
<?php if (!empty($frrStatus['running'])): ?>
<div class="frr-banner animate-in">
  <div class="frr-badge"><span class="frr-dot"></span><strong>FRRouting</strong></div>
  <div class="frr-badge">Version: <strong><?= esc($frrStatus['version']) ?></strong></div>
  <div class="frr-badge">Uptime: <strong><?= esc($frrStatus['uptime']) ?></strong></div>
  <?php if (!empty($frrStatus['bgp_as'])): ?>
    <div class="frr-badge">Local AS: <strong class="rt-chip rt-chip-blue"><?= esc($frrStatus['bgp_as']) ?></strong></div>
  <?php endif; ?>
  <?php if (!empty($frrStatus['router_id'])): ?>
    <div class="frr-badge">Router-ID: <strong class="rt-chip rt-chip-cyan"><?= esc($frrStatus['router_id']) ?></strong></div>
  <?php endif; ?>
  <?php if (!empty($isMock)): ?>
    <span class="badge badge-muted" style="margin-left:auto;">simulation mode</span>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════
     BFD PEERS
     ════════════════════════════════════════════════════════════════ -->
<div style="margin-bottom:24px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0;">BFD Peer Sessions</h3>
    <?php if (session()->get('is_superadmin') || in_array('routing.bfd.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('bfdModal')">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add BFD Peer
      </button>
    <?php endif; ?>
  </div>

  <p style="font-size:0.78rem; color:var(--text-muted); margin-bottom:12px;">
    BFD provides fast failure detection for routing protocol neighbors. When a BFD session detects a link failure, the associated routing protocol peer is notified immediately.
  </p>

  <div class="card" style="padding:0; overflow-x:auto;">
    <table class="rt-table">
      <thead><tr>
        <th>Peer IP</th>
        <th>Local IP</th>
        <th>Interface</th>
        <th>Rx / Tx (ms)</th>
        <th>Multiplier</th>
        <th>Echo Mode</th>
        <th>Detection Time</th>
        <th>Description</th>
        <th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($bfdPeers)): ?>
          <tr><td colspan="9" style="text-align:center; padding:28px; color:var(--text-muted);">No BFD peers configured. Add a BFD peer to enable fast failure detection.</td></tr>
        <?php else: ?>
          <?php foreach ($bfdPeers as $b): ?>
            <?php
              $rx = (int)($b['receive_interval'] ?? 300);
              $tx = (int)($b['transmit_interval'] ?? 300);
              $mult = (int)($b['detect_multiplier'] ?? 3);
              $detectTime = ($rx * $mult) / 1000;
            ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($b['peer_ip']) ?></span></td>
              <td><span class="rt-chip"><?= esc($b['local_ip'] ?: 'auto') ?></span></td>
              <td><span class="rt-chip"><?= esc($b['interface'] ?: 'auto') ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem;"><?= esc($rx) ?> / <?= esc($tx) ?></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem;"><?= esc($mult) ?></td>
              <td><?= $b['echo_mode'] ? '<span class="badge badge-info" style="font-size:0.65rem;">ON</span>' : '<span style="color:var(--text-muted); font-size:0.75rem;">OFF</span>' ?></td>
              <td><span class="badge badge-success" style="font-family:var(--font-mono); font-size:0.7rem;"><?= number_format($detectTime, 2) ?>s</span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($b['description'] ?: '—') ?></td>
              <td style="text-align:right;">
                <?php if (session()->get('is_superadmin') || in_array('routing.bfd.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
                  <form method="POST" action="<?= base_url('routing/bfd/' . esc($b['id']) . '/delete') ?>" style="display:inline;"
                        onsubmit="return confirm('Remove BFD peer <?= esc(addslashes($b['peer_ip'])) ?>?')"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);">
                      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>



<!-- BFD Peer Modal -->
<div class="rt-modal-backdrop" id="bfdModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">Add BFD Peer</span>
      <button type="button" onclick="closeModal('bfdModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('routing/bfd/store') ?>">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="max-height:70vh; overflow-y:auto;">
        <div class="rt-grid-2">
          <div class="rt-field"><label>Peer IP *</label><input type="text" name="peer_ip" required placeholder="10.0.0.1" class="wg-input-mono" spellcheck="false"></div>
          <div class="rt-field"><label>Local IP</label><input type="text" name="local_ip" placeholder="auto" class="wg-input-mono" spellcheck="false"><p class="hint">Leave blank for auto-detect</p></div>
        </div>
        <div class="rt-grid-3">
          <div class="rt-field"><label>Rx Interval (ms)</label><input type="number" name="receive_interval" value="300" min="10"></div>
          <div class="rt-field"><label>Tx Interval (ms)</label><input type="number" name="transmit_interval" value="300" min="10"></div>
          <div class="rt-field"><label>Detect Multiplier</label><input type="number" name="detect_multiplier" value="3" min="2" max="255"></div>
        </div>
        <div class="rt-field"><label>Interface (optional)</label><input type="text" name="interface" placeholder="eth0" class="wg-input-mono" spellcheck="false"></div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" placeholder="BGP peer liveness"></div>
        <label style="display:flex;align-items:center;gap:7px;font-size:0.82rem;color:var(--text-secondary);cursor:pointer;margin-top:4px;">
          <input type="checkbox" name="echo_mode" value="1"> Echo Mode
        </label>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('bfdModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Add BFD Peer</button>
      </div>
    </form>
  </div>
</div>

<script>
(function() {
  var tab = new URLSearchParams(window.location.search).get('tab');
  if (tab === 'bfd') {
    console.log('BFD tab active');
  }
})();
</script>

<?= $this->endSection() ?>
