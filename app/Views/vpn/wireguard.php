<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?php
  $canManage = session()->get('is_superadmin') || in_array('vpn.wireguard.manage', session()->get('permissions') ?? []);
  $tunnelCount = count($tunnels);
  $nextIface = 'wg' . $tunnelCount;

  // Per-tunnel default pre-shared keys so the Peer form can pre-fill from them.
  $tunnelPskDefaults = [];
  foreach ($tunnels as $__tunnel) {
      $tunnelPskDefaults[$__tunnel['id']] = (string) ($__tunnel['preshared_key'] ?? '');
  }
?>

<style>
/* ── Form layout ── */
.wg-section {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border-bright);
  border-radius: var(--radius-lg);
  overflow: hidden;
  margin-bottom: 24px;
}
.wg-section-header {
  background: var(--color-surface-3);
  border-bottom: 1px solid var(--color-border);
  padding: 10px 20px;
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--text-secondary);
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.wg-form-row {
  display: grid;
  grid-template-columns: 200px 1fr;
  align-items: start;
  gap: 0;
  border-bottom: 1px solid var(--color-border);
}
.wg-form-row:last-child { border-bottom: none; }
.wg-form-label {
  padding: 13px 20px;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--text-secondary);
  border-right: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.wg-form-label abbr {
  font-size: 0.72rem;
  font-weight: 400;
  color: var(--text-muted);
  text-decoration: none;
}
.wg-form-control {
  padding: 10px 20px;
  display: flex;
  flex-direction: column;
  gap: 5px;
}
.wg-form-control .hint {
  font-size: 0.72rem;
  color: var(--text-muted);
  margin: 0;
  line-height: 1.45;
}
.wg-input {
  background: var(--color-surface-3);
  border: 1px solid var(--color-border);
  color: var(--text-primary);
  padding: 7px 10px;
  border-radius: var(--radius-sm);
  font-size: 0.875rem;
  width: 100%;
  max-width: 440px;
  transition: border-color .2s;
}
.wg-input:focus { outline: none; border-color: var(--color-cyan); }
.wg-input-mono { font-family: var(--font-mono); font-size: 0.82rem; }
.wg-input-sm   { max-width: 180px; }
.wg-input-xs   { max-width: 110px; }
.wg-inline     { display: flex; gap: 10px; align-items: flex-start; flex-wrap: wrap; }
.wg-keys-row   { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.wg-keys-row .wg-input { flex: 1; min-width: 200px; }

/* ── Toggle switch ── */
.wg-toggle {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 3px 0;
}
.wg-toggle input[type="checkbox"] {
  width: 18px; height: 18px;
  accent-color: var(--color-emerald);
  cursor: pointer;
}
.wg-toggle span { font-size: 0.85rem; color: var(--text-secondary); }

/* ── Tables ── */
.wg-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 0.8125rem;
}
.wg-table th {
  background: var(--color-surface-3);
  color: var(--text-secondary);
  font-weight: 600;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 9px 14px;
  border-bottom: 1px solid var(--color-border);
  text-align: left;
  white-space: nowrap;
}
.wg-table td {
  padding: 10px 14px;
  border-bottom: 1px solid var(--color-border);
  vertical-align: middle;
}
.wg-table tbody tr:hover { background: rgba(255,255,255,0.02); }
.wg-table tbody tr:last-child td { border-bottom: none; }
.wg-empty { text-align: center; padding: 34px 20px; color: var(--text-muted); }

/* ── Modal ── */
.wg-modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.78);
  backdrop-filter: blur(5px);
  z-index: 1000;
  display: none;
  align-items: center; justify-content: center;
  padding: 20px;
}
.wg-modal-backdrop.active { display: flex; }
.wg-modal-dialog {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border-bright);
  border-radius: var(--radius-lg);
  width: 100%; max-width: 720px; max-height: 92vh;
  display: flex; flex-direction: column;
  box-shadow: 0 30px 60px -15px rgba(0,0,0,0.75);
  overflow: hidden;
  animation: wgModalIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes wgModalIn {
  from { opacity: 0; transform: translateY(-16px) scale(0.98); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}
.wg-modal-header {
  background: var(--color-surface-2);
  padding: 15px 22px;
  border-bottom: 1px solid var(--color-border);
  display: flex; justify-content: space-between; align-items: center;
}
.wg-modal-body { overflow-y: auto; flex: 1 1 auto; min-height: 0; }
/* The form wraps header-body-footer, so it must shrink too — otherwise the
   body never becomes the scroll container. */
.wg-modal-dialog > form {
  display: flex;
  flex-direction: column;
  flex: 1 1 auto;
  min-height: 0;
}
.wg-modal-footer {
  background: var(--color-surface-2);
  padding: 13px 22px;
  border-top: 1px solid var(--color-border);
  display: flex; justify-content: flex-end; gap: 10px;
}

/* ── Tunnel address cell ── */
.wg-addr {
  font-family: var(--font-mono);
  font-size: 0.82rem;
  color: var(--color-cyan);
  font-weight: 600;
}
.wg-addr-sub {
  font-family: var(--font-mono);
  font-size: 0.72rem;
  color: var(--text-muted);
}

/* ── Status badges ── */
.wg-status-dot {
  display: inline-block;
  width: 8px; height: 8px;
  border-radius: 50%;
  margin-right: 5px;
}
.wg-status-up   { background: var(--color-emerald); box-shadow: 0 0 6px var(--color-emerald); }
.wg-status-down { background: var(--color-red); }
.wg-status-idle { background: var(--color-amber); }

/* Mobile responsiveness */
@media (max-width: 640px) {
  .wg-form-row { grid-template-columns: 1fr; }
  .wg-form-label { border-right: none; border-bottom: 1px solid var(--color-border); padding: 10px 16px 6px; }
  .wg-form-control { padding: 8px 16px 12px; }
  .wg-input { max-width: 100%; }
}
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <h1 class="page-title">WireGuard VPN</h1>
        <span class="badge badge-info" style="font-size:0.73rem;"><?= $tunnelCount ?> tunnel<?= $tunnelCount === 1 ? '' : 's' ?></span>
      </div>
      <p class="page-subtitle">ChaCha20-Poly1305 crypto-key routing — every tunnel interface owns its own IP address, every peer gets its own tunnel address</p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openTunnelModal(null)">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
          </svg>
          Add Tunnel
        </button>
        <button type="button" class="btn btn-primary btn-sm" onclick="openPeerModal(null)">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
          </svg>
          Add Peer
        </button>
      <?php endif; ?>
      <?= \App\Libraries\ServiceStatus::widget('wireguard') ?>
    </div>
  </div>
</div>

<!-- ── Flash ── -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>
<?php if ($isMock): ?>
  <div class="alert animate-in" data-persist style="margin-bottom:18px; background:var(--color-surface-2); border:1px solid var(--color-border); color:var(--text-secondary); font-size:0.8rem;">
    ⚠ Mock mode — the daemon is simulated on this host. Interface addresses are compiled and stored, but no kernel interface is created here.
  </div>
<?php endif; ?>

<!-- Explains why an action could not proceed, instead of a dead button -->
<div class="alert animate-in" id="wgNotice" data-persist style="display:none; margin-bottom:18px; background:var(--color-surface-2); border:1px solid var(--color-amber); color:var(--text-secondary); font-size:0.82rem; align-items:flex-start; gap:10px;">
  <span id="wgNoticeText" style="flex:1;"></span>
  <button type="button" onclick="document.getElementById('wgNotice').style.display='none'"
          title="Dismiss" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1rem; line-height:1; padding:0 2px;">✕</button>
</div>

<!-- ================================================================
     SECTION 1 — Tunnels (interfaces and their addresses)
     ================================================================ -->
<div class="wg-section animate-in">
  <div class="wg-section-header" style="justify-content:space-between;">
    <span style="display:flex; align-items:center; gap:8px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
      </svg>
      Tunnels — interface address, listen port and keys
    </span>
  </div>

  <div style="overflow-x:auto;">
    <table class="wg-table">
      <thead>
        <tr>
          <th style="min-width:150px;">Tunnel</th>
          <th style="min-width:120px;">Interface</th>
          <th style="min-width:170px;">Interface Address (this appliance)</th>
          <th style="width:110px;">Listen Port</th>
          <th style="width:80px; text-align:center;">MTU</th>
          <th style="width:70px; text-align:center;">Peers</th>
          <th style="width:90px; text-align:center;">Status</th>
          <th style="min-width:190px; text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($tunnels === []): ?>
          <tr>
            <td colspan="8" class="wg-empty">
              No WireGuard tunnel interface defined yet.<br>
              <span style="font-size:0.78rem;">Add a tunnel to create the interface (wg0), give it an IP address and a listen port — peers are attached to a tunnel.</span>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($tunnels as $t): ?>
            <?php
              $enabled = (int) ($t['enabled'] ?? 1) === 1;
              // Prefer the multi-address list, fall back to the legacy pair
              $addresses = App\Libraries\Cidr::split((string) ($t['addresses'] ?? ''));
              if ($addresses === []) {
                  $legacy = trim((string) ($t['ipv4_address'] ?? ''));
                  if ($legacy !== '') {
                      if (! str_contains($legacy, '/') && ! empty($t['ipv4_subnet'])) {
                          $legacy .= '/' . (int) $t['ipv4_subnet'];
                      }
                      $addresses = [$legacy];
                  }
              }
            ?>
            <tr>
              <td>
                <strong style="color:var(--text-primary);"><?= esc($t['name'] ?: $t['interface_name']) ?></strong>
                <?php if (! empty($t['description'])): ?>
                  <div style="font-size:0.72rem; color:var(--text-muted);"><?= esc($t['description']) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="badge badge-muted" style="font-family:var(--font-mono);"><?= esc($t['interface_name']) ?></span></td>
              <td>
                <?php if ($addresses === []): ?>
                  <span class="wg-addr">— not set —</span>
                  <div class="wg-addr-sub">Interface has no address; peers cannot route to it.</div>
                <?php else: ?>
                  <?php foreach ($addresses as $addr): ?>
                    <div class="wg-addr"><?= esc($addr) ?></div>
                  <?php endforeach; ?>
                  <div class="wg-addr-sub">
                    <?= count($addresses) ?> address<?= count($addresses) === 1 ? '' : 'es' ?> on this interface
                  </div>
                <?php endif; ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.82rem;"><?= (int) ($t['listen_port'] ?? 51820) ?></td>
              <td style="text-align:center; font-family:var(--font-mono); font-size:0.8rem; color:var(--text-secondary);"><?= (int) ($t['mtu'] ?? 1420) ?></td>
              <td style="text-align:center;">
                <span class="badge badge-info" style="font-size:0.68rem;"><?= (int) ($peerCounts[$t['id']] ?? 0) ?></span>
              </td>
              <td style="text-align:center;">
                <span style="font-size:0.75rem; white-space:nowrap;">
                  <span class="wg-status-dot <?= $enabled ? 'wg-status-up' : 'wg-status-down' ?>"></span>
                  <?= $enabled ? 'Up' : 'Down' ?>
                </span>
              </td>
              <td style="text-align:right; white-space:nowrap;">
                <a href="<?= base_url('vpn/wireguard/tunnels/' . esc($t['id']) . '/config') ?>"
                   class="btn btn-ghost btn-sm" title="Download /etc/wireguard/<?= esc($t['interface_name']) ?>.conf" style="padding:4px 9px; font-size:0.72rem;">.conf</a>
                <a href="<?= base_url('vpn/wireguard/tunnels/' . esc($t['id']) . '/apply-script') ?>"
                   class="btn btn-ghost btn-sm" title="Download the ip commands that address and bring up this interface" style="padding:4px 9px; font-size:0.72rem;">ip script</a>
                <?php if ($canManage): ?>
                  <form method="POST" action="<?= base_url('vpn/wireguard/tunnels/' . esc($t['id']) . '/genkey') ?>" style="display:inline;"
                        onsubmit="return confirm('Generate a new keypair for <?= esc(addslashes($t['interface_name'])) ?>? Existing peers must be re-keyed.');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" title="Generate a new interface keypair via the daemon" style="padding:4px 9px; font-size:0.72rem;">Keys</button>
                  </form>
                  <button type="button" class="btn btn-ghost btn-sm"
                          onclick='openTunnelModal(<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>)'
                          title="Edit tunnel" style="padding:4px 9px; font-size:0.72rem;">Edit</button>
                  <form method="POST" action="<?= base_url('vpn/wireguard/tunnels/' . esc($t['id']) . '/toggle') ?>" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" title="<?= $enabled ? 'Bring interface down' : 'Bring interface up' ?>" style="padding:4px 9px; font-size:0.72rem;"><?= $enabled ? 'Down' : 'Up' ?></button>
                  </form>
                  <form method="POST" action="<?= base_url('vpn/wireguard/tunnels/' . esc($t['id']) . '/delete') ?>" style="display:inline;"
                        onsubmit="return confirm('Delete tunnel <?= esc(addslashes($t['interface_name'])) ?> and all of its peers?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" title="Delete tunnel" style="color:var(--color-red); padding:4px 9px; font-size:0.72rem;">Del</button>
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

<!-- ================================================================
     SECTION 2 — Peers
     ================================================================ -->
<div class="wg-section animate-in animate-in-delay-1">
  <div class="wg-section-header" style="justify-content:space-between;">
    <span style="display:flex; align-items:center; gap:8px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
      </svg>
      Peers — tunnel address of the other end plus the networks routed to it
    </span>
    <?php if ($canManage): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openPeerModal(null)" style="font-size:0.75rem; padding:4px 12px;">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Add peer
      </button>
    <?php endif; ?>
  </div>

  <div style="overflow-x:auto;">
    <table class="wg-table">
      <thead>
        <tr>
          <th style="min-width:110px;">Tunnel</th>
          <th style="min-width:130px;">Description</th>
          <th style="min-width:150px;">Peer Tunnel Address</th>
          <th style="min-width:160px;">Networks Routed Through Peer</th>
          <th style="min-width:150px;">Public Key</th>
          <th style="min-width:150px;">Endpoint</th>
          <th style="width:70px; text-align:center;">KA (s)</th>
          <th style="width:90px; text-align:center;">Status</th>
          <th style="width:120px; text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($peers)): ?>
          <tr>
            <td colspan="9" class="wg-empty">
              <?= $tunnelCount === 0
                    ? 'No tunnel interface defined yet, and every peer belongs to one — <strong>Add Peer</strong> will take you straight to the tunnel form.'
                    : 'No peers defined yet — click <strong>Add peer</strong> to allow road-warrior or site-to-site access.' ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($peers as $p): ?>
            <?php
              $live = $liveData[$p['public_key']] ?? null;
              if ($live && ! empty($live['latest_handshake']) && is_numeric($live['latest_handshake']) && (time() - (int) $live['latest_handshake'] < 180)) {
                  $statusClass = 'wg-status-up';
                  $statusLabel = 'Active';
              } elseif ($live) {
                  $statusClass = 'wg-status-idle';
                  $statusLabel = 'Seen';
              } else {
                  $statusClass = 'wg-status-down';
                  $statusLabel = empty($p['enabled']) ? 'Disabled' : 'No handshake';
              }
            ?>
            <tr>
              <td>
                <span class="badge badge-muted" style="font-family:var(--font-mono); font-size:0.68rem;">
                  <?= esc($tunnelNames[$p['tunnel_id']] ?? '— unassigned') ?>
                </span>
              </td>
              <td>
                <strong style="color:var(--text-primary);"><?= esc($p['name']) ?></strong>
              </td>
              <td>
                <span class="wg-addr"><?= esc($p['peer_address'] ?: '— not set —') ?></span>
                <?php if (empty($p['peer_address'])): ?>
                  <div class="wg-addr-sub">Add the peer's own tunnel IP so the interface knows where to send its traffic.</div>
                <?php endif; ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);">
                <?= esc($p['allowed_ips'] ?: '—') ?>
              </td>
              <td>
                <span style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);" title="<?= esc($p['public_key']) ?>">
                  <?= esc(substr((string) $p['public_key'], 0, 20)) ?>…
                </span>
                <button type="button" onclick="copyText('<?= esc(addslashes((string) $p['public_key'])) ?>')"
                        title="Copy full public key"
                        style="background:none; border:none; color:var(--text-muted); cursor:pointer; padding:2px 4px; vertical-align:middle;">
                  <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                  </svg>
                </button>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);">
                <?= esc($p['endpoint'] ?: '—') ?>
              </td>
              <td style="text-align:center; font-family:var(--font-mono); font-size:0.78rem; color:var(--text-muted);">
                <?= (int) ($p['persistent_keepalive'] ?? 25) ?>
              </td>
              <td style="text-align:center;">
                <span style="font-size:0.75rem; white-space:nowrap;">
                  <span class="wg-status-dot <?= $statusClass ?>"></span>
                  <?= $statusLabel ?>
                </span>
              </td>
              <td style="text-align:right; white-space:nowrap;">
                <a href="<?= base_url('vpn/wireguard/peers/' . esc($p['id']) . '/download') ?>"
                   class="btn btn-ghost btn-sm" title="Download the peer's .conf profile" style="padding:4px 7px; font-size:0.72rem;">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                  </svg>
                </a>
                <?php if ($canManage): ?>
                  <button type="button" class="btn btn-ghost btn-sm"
                          onclick='openPeerModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)'
                          title="Edit peer" style="padding:4px 7px; font-size:0.72rem;">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/>
                    </svg>
                  </button>
                  <form method="POST" action="<?= base_url('vpn/wireguard/peers/' . esc($p['id']) . '/delete') ?>"
                        style="display:inline;"
                        onsubmit="return confirm('Remove peer <?= esc(addslashes($p['name'])) ?>?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" title="Delete peer"
                            style="color:var(--color-red); padding:4px 7px; font-size:0.72rem;">
                      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                      </svg>
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

<!-- ================================================================
     SECTION 3 — How WireGuard addresses a tunnel
     ================================================================ -->
<div class="wg-section animate-in animate-in-delay-2">
  <div class="wg-section-header">Addressing model — who gets which IP</div>
  <div style="padding:18px 20px;">
    <table class="wg-table" style="margin-bottom:14px;">
      <thead>
        <tr>
          <th>Address</th>
          <th>Where it lives</th>
          <th>Example</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong style="color:var(--text-primary);">Interface address</strong></td>
          <td>The tunnel interface on <em>this</em> appliance (Tunnels table → Interface Address). Applied with <code>ip address add</code>; the download button gives you that exact script.</td>
          <td class="wg-addr">10.9.0.1/24</td>
        </tr>
        <tr>
          <td><strong style="color:var(--text-primary);">Peer tunnel address</strong></td>
          <td>The other end's tunnel interface IP. WireGuard has no dedicated peer-address keyword, so it is compiled as the first <code>AllowedIPs</code> entry — a /32 host route over the cryptokey.</td>
          <td class="wg-addr">10.9.0.2/32</td>
        </tr>
        <tr>
          <td><strong style="color:var(--text-primary);">Networks routed through peer</strong></td>
          <td>Extra CIDRs appended to that same <code>AllowedIPs</code> list, plus an explicit route in the apply script when they are wider than a host.</td>
          <td class="wg-addr">192.168.20.0/24</td>
        </tr>
      </tbody>
    </table>
    <p style="font-size:0.75rem; color:var(--text-muted); margin:0 0 14px; line-height:1.6;">
      Both ends of a tunnel should use addresses inside the same tunnel subnet (10.9.0.1/24 and 10.9.0.2/24, or /32 each) that
      does not overlap any physical LAN. The interface address is per-appliance; the peer address is per-peer. Keys come from the
      daemon (<code>wg genkey</code> / <code>wg pubkey</code>) — use the <em>Keys</em> button on a saved tunnel.
    </p>

    <h4 style="font-size:0.78rem; font-weight:700; color:var(--color-cyan); margin:0 0 8px; text-transform:uppercase; letter-spacing:0.05em;">Multiple entries are expected, not the exception</h4>
    <table class="wg-table">
      <thead>
        <tr>
          <th>Situation</th>
          <th>What to enter</th>
          <th>Compiled to</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Dual-stack tunnel</td>
          <td>Interface: <code>10.9.0.1/24, fd00::1/64</code></td>
          <td><code>Address = 10.9.0.1/24, fd00::1/64</code> + one <code>ip address add</code> per entry</td>
        </tr>
        <tr>
          <td>Site-to-site with several LANs</td>
          <td>Peer networks: <code>192.168.20.0/24, 172.16.5.0/24</code></td>
          <td><code>AllowedIPs = …/32, 192.168.20.0/24, 172.16.5.0/24</code> plus a route per network</td>
        </tr>
        <tr>
          <td>Peer with its own v4 + v6 tunnel IP</td>
          <td>Peer address: <code>10.9.0.2/32, fd00::2/128</code></td>
          <td>Both become leading <code>AllowedIPs</code> host routes</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ================================================================
     MODAL — Tunnel (interface) add / edit
     ================================================================ -->
<div class="wg-modal-backdrop" id="tunnelModal">
  <div class="wg-modal-dialog">
    <div class="wg-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="tunnelModalTitle">Add WireGuard Tunnel</span>
      <button type="button" onclick="closeTunnelModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.25rem; line-height:1;">&times;</button>
    </div>

    <form method="POST" id="tunnelForm" action="<?= base_url('vpn/wireguard/tunnels/store') ?>">
      <?= csrf_field() ?>
      <div class="wg-modal-body modal-scroll">

        <div class="wg-form-row">
          <div class="wg-form-label">Name<abbr>Tunnel label</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="name" id="tunName" class="wg-input" required maxlength="64" placeholder="e.g. HQ-Backbone">
            <p class="hint">Administrative name for this tunnel .</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Description<abbr>Optional</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="description" id="tunDescription" class="wg-input" maxlength="190" placeholder="e.g. Site-to-site to branch office">
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Interface<abbr>Kernel device</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="interface_name" id="tunIface" class="wg-input wg-input-mono wg-input-sm" required maxlength="15" value="<?= esc($nextIface)?>" spellcheck="false">
            <p class="hint">Up to 15 characters, no spaces (wg0, wg1, …).</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Interface Address(es)<abbr>Tunnel IPs of this appliance</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="addresses" id="tunAddresses" class="wg-input wg-input-mono" placeholder="10.9.0.1/24, fd00::1/64" style="max-width:100%;" spellcheck="false">
            <p class="hint">
              One or more addresses for the tunnel interface itself — separate them with commas
              (<code>10.9.0.1/24, fd00::1/64</code>). Each entry becomes an <code>ip address add</code> in the apply script and a
              comma-separated entry in <code>Address =</code>. Prefixes are required for subnets; a bare IP defaults to /24 (IPv4) or /64 (IPv6).
            </p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Listen Port<abbr>UDP</abbr></div>
          <div class="wg-form-control">
            <input type="number" name="listen_port" id="tunListenPort" class="wg-input wg-input-mono wg-input-sm" value="51820" min="1" max="65535" required spellcheck="false">
            <p class="hint">UDP port this interface listens on. Default 51820.</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">MTU<abbr>Optional</abbr></div>
          <div class="wg-form-control">
            <input type="number" name="mtu" id="tunMtu" class="wg-input wg-input-mono wg-input-xs" value="1420" min="576" max="9000" spellcheck="false">
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Interface Keys<abbr>Keypair</abbr></div>
          <div class="wg-form-control">
            <div class="wg-keys-row">
              <div style="flex:1; min-width:210px;">
                <p style="font-size:0.7rem; color:var(--text-muted); margin:0 0 4px;">Private key (base64)</p>
                <input type="password" name="private_key" id="tunPrivateKey" class="wg-input wg-input-mono" placeholder="Paste private key" autocomplete="off" spellcheck="false">
              </div>
              <div style="flex:1; min-width:210px;">
                <p style="font-size:0.7rem; color:var(--text-muted); margin:0 0 4px;">Public key (base64)</p>
                <input type="text" name="public_key" id="tunPublicKey" class="wg-input wg-input-mono" placeholder="Paste public key" autocomplete="off" spellcheck="false">
              </div>
              <div style="align-self:flex-end; padding-bottom:1px;">
                <button type="button" class="btn btn-ghost btn-sm" id="tunGenKeyBtn" onclick="wgGenerateKeypair()"
                        title="Generate a fresh daemon keypair (wg genkey | wg pubkey) and fill both fields"
                        style="white-space:nowrap;">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                  </svg>
                  Generate
                </button>
              </div>
            </div>
            <p class="hint">
              <strong>Generate</strong> fills both fields with a new keypair from the daemon
              (<code>wg genkey | wg pubkey</code>) — works before the tunnel is saved. You can also paste an existing keypair,
              or use the <strong>Keys</strong> button on a saved tunnel's row to re-key it later.
            </p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Pre-shared Key<abbr>Optional — tunnel default</abbr></div>
          <div class="wg-form-control">
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
              <input type="text" name="preshared_key" id="tunPresharedKey" class="wg-input wg-input-mono" placeholder="Leave blank to skip" autocomplete="off" style="flex:1; min-width:220px; max-width:none;" spellcheck="false">
              <button type="button" class="btn btn-ghost btn-sm" id="tunGenPskBtn" onclick="wgGenerateTunnelPsk()"
                      title="Generate a random pre-shared key (wg genpsk) as this tunnel's default"
                      style="white-space:nowrap;">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Generate
              </button>
            </div>
            <p class="hint">
              Optional symmetric key for post-quantum resistance. WireGuard keeps the PSK on the peer, so this value is the
              tunnel's <em>default</em> — every peer created under it inherits it unless the peer has its own. Generate one and
              paste the same value into the remote end's config.
            </p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">DNS Servers<abbr>Optional</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="dns_servers" id="tunDns" class="wg-input wg-input-mono" placeholder="10.9.0.1, 1.1.1.1" spellcheck="false">
            <p class="hint">Handed to clients that download their profile from this tunnel.</p>
          </div>
        </div>

        <div class="wg-form-row" style="border-bottom:none;">
          <div class="wg-form-label">Enabled</div>
          <div class="wg-form-control">
            <div class="wg-toggle">
              <input type="checkbox" name="enabled" id="tunEnabled" value="1" checked>
              <span>Create the interface and bring it up</span>
            </div>
          </div>
        </div>

      </div>

      <div class="wg-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeTunnelModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="tunnelSubmitBtn" style="min-width:130px;">Save Tunnel</button>
      </div>
    </form>
  </div>
</div>

<!-- ================================================================
     MODAL — Peer add / edit
     ================================================================ -->
<div class="wg-modal-backdrop" id="peerModal">
  <div class="wg-modal-dialog">
    <div class="wg-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="peerModalTitle">Add WireGuard Peer</span>
      <button type="button" onclick="closePeerModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.25rem; line-height:1;">&times;</button>
    </div>

    <form method="POST" id="peerForm" action="<?= base_url('vpn/wireguard/peers/store') ?>">
      <?= csrf_field() ?>
      <div class="wg-modal-body modal-scroll">

        <div class="wg-form-row">
          <div class="wg-form-label">Tunnel<abbr>Parent interface</abbr></div>
          <div class="wg-form-control">
            <select name="tunnel_id" id="peerTunnel" class="wg-input" required style="max-width:440px;">
              <?php foreach ($tunnels as $t): ?>
                <option value="<?= esc($t['id']) ?>"><?= esc(($t['name'] ?: $t['interface_name']) . ' (' . $t['interface_name'] . ')') ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint">Which WireGuard interface this peer is attached to.</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Description<abbr>Peer / user name</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="name" id="peerName" class="wg-input" required maxlength="64" placeholder="e.g. branch-office or alice-laptop">
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Public Key<abbr>Base64, 44 chars</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="public_key" id="peerPublicKey" class="wg-input wg-input-mono" required placeholder="e.g. 4+gK7uXmZ1K8…=" autocomplete="off" spellcheck="false">
            <p class="hint">The peer's WireGuard public key (generated on the remote device).</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Peer Tunnel Address(es)<abbr>The other end's tunnel IPs</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="peer_address" id="peerAddress" class="wg-input wg-input-mono" placeholder="e.g. 10.9.0.2/32, fd00::2/128" style="max-width:100%;" spellcheck="false">
            <p class="hint">
              Address(es) of the peer's own tunnel interface — one per address the peer holds (IPv4 and/or IPv6).
              Compiled as the leading <code>AllowedIPs</code> entries (cryptokey routes). Use /32 or /128 per host, or a wider prefix
              to let the peer serve a whole tunnel subnet.
            </p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Networks Routed Through Peer<abbr>Peer's LANs</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="allowed_ips" id="peerAllowedIPs" class="wg-input wg-input-mono" placeholder="192.168.20.0/24, 172.16.5.0/24, fd00:20::/64" style="max-width:100%;" spellcheck="false">
            <p class="hint">Any number of CIDRs, comma-separated — every network that should be reachable through this peer. Each wider-than-host entry also gets an explicit route in the interface apply script.</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Pre-shared Key<abbr>Optional</abbr></div>
          <div class="wg-form-control">
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
              <input type="text" name="preshared_key" id="peerPresharedKey" class="wg-input wg-input-mono" placeholder="Leave blank to skip" autocomplete="off" style="flex:1; min-width:220px; max-width:none;" spellcheck="false">
              <button type="button" class="btn btn-ghost btn-sm" id="peerGenPskBtn" onclick="wgGeneratePsk()"
                      title="Generate a random pre-shared key (wg genpsk) and paste the same value on the remote end"
                      style="white-space:nowrap;">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Generate
              </button>
            </div>
            <p class="hint">Optional symmetric key for post-quantum resistance — identical on both ends. In WireGuard the PSK belongs to a <em>peer</em>, not the interface, so each peer can have its own. Generate one, then paste the same value into the peer's config on the remote end.</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Endpoint<abbr>Optional host:port</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="endpoint" id="peerEndpoint" class="wg-input wg-input-mono" placeholder="e.g. 203.0.113.5:51820" spellcheck="false">
            <p class="hint">Fixed endpoint for site-to-site tunnels. Leave blank for road-warrior / dynamic NAT clients.</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Persistent Keepalive<abbr>Seconds (0 = off)</abbr></div>
          <div class="wg-form-control">
            <input type="number" name="keepalive_secs" id="peerKeepalive" class="wg-input wg-input-sm" value="25" min="0" max="3600">
            <p class="hint">Send keepalive packets every N seconds. 25 is recommended for NAT traversal.</p>
          </div>
        </div>

        <div class="wg-form-row" style="border-bottom:none;">
          <div class="wg-form-label">Enabled</div>
          <div class="wg-form-control">
            <div class="wg-toggle">
              <input type="checkbox" name="enabled" id="peerEnabled" value="1" checked>
              <span>Peer is active</span>
            </div>
          </div>
        </div>

      </div>

      <div class="wg-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closePeerModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="peerSubmitBtn" style="min-width:110px;">Save Peer</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Copy helper ──
function copyText(text) {
  navigator.clipboard.writeText(text).then(function () {
    var el = window.event ? window.event.currentTarget : null;
    if (!el) return;
    var orig = el.style.color;
    el.style.color = 'var(--color-emerald)';
    setTimeout(function () { el.style.color = orig; }, 900);
  });
}

// Per-tunnel default PSKs (id → key), used to pre-fill the peer form.
var wgTunnelPskDefaults = <?= json_encode($tunnelPskDefaults, JSON_UNESCAPED_SLASHES) ?>;

// ── Key / PSK generation (AJAX, works before the record is saved) ──
function wgSyncCsrf(token) {
  if (!token) return;
  document.querySelectorAll('input[name="csrf_test_name"]').forEach(function (input) {
    input.value = token;
  });
}

function wgRequestKey(params, button) {
  var tokenInput = document.querySelector('input[name="csrf_test_name"]');
  var original = button ? button.innerHTML : null;
  if (button) { button.disabled = true; button.classList.add('is-loading'); }

  var body = new URLSearchParams(params);
  if (tokenInput) body.set('csrf_test_name', tokenInput.value);

  return MicronScheduler.fetch('<?= base_url('vpn/wireguard/keygen') ?>', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: body
  }).then(function (response) {
    return response.json().then(function (json) {
      return { ok: response.ok, json: json };
    });
  }).then(function (res) {
    // The token rotates on every POST — keep the hidden fields current so the
    // subsequent Save still validates.
    wgSyncCsrf(res.json && res.json.csrf);
    if (!res.ok || !res.json || res.json.ok === false) {
      throw new Error((res.json && res.json.error) || 'Key generation failed.');
    }
    return res.json;
  }).finally(function () {
    if (button) { button.disabled = false; button.classList.remove('is-loading'); button.innerHTML = original; }
  });
}

function wgGenerateKeypair() {
  wgRequestKey({ kind: 'keypair' }, document.getElementById('tunGenKeyBtn')).then(function (data) {
    document.getElementById('tunPrivateKey').value = data.private_key;
    document.getElementById('tunPublicKey').value  = data.public_key;
  }).catch(function (err) {
    wgNotice('<strong style="color:var(--color-amber);">Could not generate a keypair.</strong> ' + err.message);
  });
}

function wgGeneratePsk() {
  wgRequestKey({ kind: 'psk' }, document.getElementById('peerGenPskBtn')).then(function (data) {
    document.getElementById('peerPresharedKey').value = data.preshared_key;
  }).catch(function (err) {
    wgNotice('<strong style="color:var(--color-amber);">Could not generate a pre-shared key.</strong> ' + err.message);
  });
}

function wgGenerateTunnelPsk() {
  wgRequestKey({ kind: 'psk' }, document.getElementById('tunGenPskBtn')).then(function (data) {
    document.getElementById('tunPresharedKey').value = data.preshared_key;
  }).catch(function (err) {
    wgNotice('<strong style="color:var(--color-amber);">Could not generate a pre-shared key.</strong> ' + err.message);
  });
}

// ── Tunnel modal ──
function openTunnelModal(tunnel) {
  var form = document.getElementById('tunnelForm');
  var editing = !!tunnel;

  form.action = editing
    ? '<?= base_url('vpn/wireguard/tunnels') ?>/' + encodeURIComponent(tunnel.id) + '/update'
    : '<?= base_url('vpn/wireguard/tunnels/store') ?>';

  // Multi-address list, with a fallback to the legacy single address + prefix
  var addresses = (tunnel && tunnel.addresses) ? tunnel.addresses : '';
  if (!addresses && tunnel && tunnel.ipv4_address) {
    addresses = tunnel.ipv4_address;
    if (addresses.indexOf('/') === -1 && tunnel.ipv4_subnet) {
      addresses += '/' + tunnel.ipv4_subnet;
    }
  }

  document.getElementById('tunnelModalTitle').innerText = editing ? 'Edit WireGuard Tunnel' : 'Add WireGuard Tunnel';
  document.getElementById('tunnelSubmitBtn').innerText = editing ? 'Update Tunnel' : 'Save Tunnel';

  document.getElementById('tunName').value        = (tunnel && tunnel.name) || '';
  document.getElementById('tunDescription').value = (tunnel && tunnel.description) || '';
  document.getElementById('tunIface').value       = (tunnel && tunnel.interface_name) || '<?= esc($nextIface) ?>';
  document.getElementById('tunAddresses').value   = addresses;
  document.getElementById('tunListenPort').value  = (tunnel && tunnel.listen_port) || 51820;
  document.getElementById('tunMtu').value         = (tunnel && tunnel.mtu) || 1420;
  document.getElementById('tunPrivateKey').value  = '';
  document.getElementById('tunPublicKey').value   = (tunnel && tunnel.public_key) || '';
  document.getElementById('tunPresharedKey').value = (tunnel && tunnel.preshared_key) || '';
  document.getElementById('tunDns').value         = (tunnel && tunnel.dns_servers) || '';
  document.getElementById('tunEnabled').checked   = !tunnel || (parseInt(tunnel.enabled) !== 0);

  document.getElementById('tunnelModal').classList.add('active');
  // Reset AFTER display: a hidden scroll container ignores scrollTop.
  document.querySelector('#tunnelModal .wg-modal-body').scrollTop = 0;
}
function closeTunnelModal() {
  document.getElementById('tunnelModal').classList.remove('active');
}
document.getElementById('tunnelModal').addEventListener('click', function (e) {
  if (e.target === this) closeTunnelModal();
});

// ── Inline notice (replaces silently-dead buttons) ──
function wgNotice(message) {
  var box = document.getElementById('wgNotice');
  var text = document.getElementById('wgNoticeText');
  if (!box || !text) return;
  text.innerHTML = message;
  box.style.display = 'flex';
}

// ── Peer modal ──
function openPeerModal(peer) {
  var form = document.getElementById('peerForm');
  var editing = !!peer;
  var select = document.getElementById('peerTunnel');

  // A peer is always attached to a tunnel interface. Without one there is
  // nothing to save against, so send the user to the form they actually need
  // rather than leaving the click doing nothing.
  if (!editing && select.options.length === 0) {
    wgNotice('<strong style="color:var(--color-amber);">A WireGuard peer needs a tunnel interface first.</strong> '
      + 'Opening the Add Tunnel form — save a tunnel (it gets the interface name, IP address and listen port), '
      + 'then add peers to it.');
    openTunnelModal(null);
    return;
  }

  // An existing peer whose tunnel has been removed must not be silently
  // re-pointed at whichever tunnel happens to be first in the list.
  if (editing && peer.tunnel_id && !Array.prototype.some.call(select.options, function (o) { return o.value === peer.tunnel_id; })) {
    var orphan = document.createElement('option');
    orphan.value = peer.tunnel_id;
    orphan.textContent = '⚠ missing tunnel (' + peer.tunnel_id + ')';
    select.appendChild(orphan);
  }

  form.action = editing
    ? '<?= base_url('vpn/wireguard/peers') ?>/' + encodeURIComponent(peer.id) + '/update'
    : '<?= base_url('vpn/wireguard/peers/store') ?>';

  document.getElementById('peerModalTitle').innerText = editing ? 'Edit Peer — ' + peer.name : 'Add WireGuard Peer';
  document.getElementById('peerSubmitBtn').innerText  = editing ? 'Update Peer' : 'Save Peer';

  select.value                                     = (peer && peer.tunnel_id) || (select.options.length ? select.options[0].value : '');
  document.getElementById('peerName').value        = (peer && peer.name) || '';
  document.getElementById('peerPublicKey').value   = (peer && peer.public_key) || '';
  document.getElementById('peerAddress').value     = (peer && peer.peer_address) || '';
  document.getElementById('peerAllowedIPs').value  = (peer && peer.allowed_ips) || '';
  // A new peer starts from its tunnel's default PSK; an existing peer keeps its own.
  document.getElementById('peerPresharedKey').value= (peer && peer.preshared_key)
    || wgTunnelPskDefaults[select.value] || '';
  document.getElementById('peerEndpoint').value    = (peer && peer.endpoint) || '';
  document.getElementById('peerKeepalive').value   = (peer && peer.persistent_keepalive) || 25;
  document.getElementById('peerEnabled').checked   = !peer || (parseInt(peer.enabled) !== 0);

  document.getElementById('peerModal').classList.add('active');
  // Reset AFTER display: a hidden scroll container ignores scrollTop.
  document.querySelector('#peerModal .wg-modal-body').scrollTop = 0;
}
function closePeerModal() {
  document.getElementById('peerModal').classList.remove('active');
}
document.getElementById('peerModal').addEventListener('click', function (e) {
  if (e.target === this) closePeerModal();
});

// Follow the tunnel's default PSK while the peer form is still blank.
document.getElementById('peerTunnel').addEventListener('change', function () {
  var field = document.getElementById('peerPresharedKey');
  if (field.value.trim() === '') {
    field.value = wgTunnelPskDefaults[this.value] || '';
  }
});

document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') { closeTunnelModal(); closePeerModal(); }
});
</script>

<?= $this->endSection() ?>
