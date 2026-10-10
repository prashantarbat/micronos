<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?php
  $canManage  = session()->get('is_superadmin') || in_array('vpn.openvpn.manage', session()->get('permissions') ?? []);
  $canExport  = session()->get('is_superadmin') || in_array('vpn.openvpn.cert.export', session()->get('permissions') ?? []);
  $running    = (bool) ($liveStatus['running'] ?? false);
  $livePeers  = (int) ($liveStatus['clients'] ?? 0);
?>

<style>
/* ── Tabs ── */
.ov-tabs {
  display: flex;
  gap: 2px;
  border-bottom: 1px solid var(--color-border);
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.ov-tab-btn {
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  padding: 10px 18px;
  font-size: 0.83rem;
  font-weight: 600;
  color: var(--text-muted);
  cursor: pointer;
  transition: color .2s, border-color .2s;
}
.ov-tab-btn:hover  { color: var(--text-primary); border-bottom-color: var(--color-border-bright); }
.ov-tab-btn.active { color: var(--color-cyan); border-bottom-color: var(--color-cyan); }

/* ── Section + form rows ── */
.ov-section {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border-bright);
  border-radius: var(--radius-lg);
  overflow: hidden;
  margin-bottom: 24px;
}
.ov-section-header {
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
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
}
.ov-form-row {
  display: grid;
  grid-template-columns: 210px 1fr;
  align-items: start;
  border-bottom: 1px solid var(--color-border);
}
.ov-form-row:last-child { border-bottom: none; }
.ov-form-label {
  padding: 13px 20px;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--text-secondary);
  border-right: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.ov-form-label abbr { font-size: 0.72rem; font-weight: 400; color: var(--text-muted); text-decoration: none; }
.ov-form-control { padding: 10px 20px; display: flex; flex-direction: column; gap: 5px; }
.ov-form-control .hint { font-size: 0.72rem; color: var(--text-muted); margin: 0; line-height: 1.45; }
.ov-input, .ov-select {
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
.ov-input:focus, .ov-select:focus { outline: none; border-color: var(--color-cyan); }
.ov-input-mono { font-family: var(--font-mono); font-size: 0.82rem; }
.ov-input-sm   { max-width: 180px; }
.ov-toggle { display: flex; align-items: center; gap: 10px; padding: 3px 0; }
.ov-toggle input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--color-emerald); cursor: pointer; }
.ov-toggle span { font-size: 0.85rem; color: var(--text-secondary); }

/* ── Tables ── */
.ov-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.8125rem; }
.ov-table th {
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
.ov-table td { padding: 10px 14px; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
.ov-table tbody tr:hover { background: rgba(255,255,255,0.02); }
.ov-table tbody tr:last-child td { border-bottom: none; }
.ov-empty { text-align: center; padding: 34px 20px; color: var(--text-muted); }

/* ── Modal ── */
.ov-modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.78);
  backdrop-filter: blur(5px);
  z-index: 1000;
  display: none;
  align-items: center; justify-content: center;
  padding: 20px;
}
.ov-modal-backdrop.active { display: flex; }
.ov-modal-dialog {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border-bright);
  border-radius: var(--radius-lg);
  width: 100%; max-width: 720px; max-height: 92vh;
  display: flex; flex-direction: column;
  box-shadow: 0 30px 60px -15px rgba(0,0,0,0.75);
  overflow: hidden;
  animation: ovModalIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes ovModalIn {
  from { opacity: 0; transform: translateY(-16px) scale(0.98); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}
.ov-modal-header {
  background: var(--color-surface-2);
  padding: 15px 22px;
  border-bottom: 1px solid var(--color-border);
  display: flex; justify-content: space-between; align-items: center;
}
.ov-modal-body { overflow-y: auto; flex: 1 1 auto; min-height: 0; }
/* The form wraps header-body-footer, so it must shrink too — otherwise the
   body never becomes the scroll container and the footer ends up off-screen. */
.ov-modal-dialog > form {
  display: flex;
  flex-direction: column;
  flex: 1 1 auto;
  min-height: 0;
}
.ov-modal-footer {
  background: var(--color-surface-2);
  padding: 13px 22px;
  border-top: 1px solid var(--color-border);
  display: flex; justify-content: flex-end; gap: 10px;
}

/* ── Status dot ── */
.ov-status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; }
.ov-status-up   { background: var(--color-emerald); box-shadow: 0 0 6px var(--color-emerald); }
.ov-status-down { background: var(--color-red); }
.ov-status-idle { background: var(--color-amber); }

@media (max-width: 640px) {
  .ov-form-row { grid-template-columns: 1fr; }
  .ov-form-label { border-right: none; border-bottom: 1px solid var(--color-border); padding: 10px 16px 6px; }
  .ov-form-control { padding: 8px 16px 12px; }
  .ov-input, .ov-select { max-width: 100%; }
}
</style>

<!-- ── Page header ── -->
<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <h1 class="page-title">OpenVPN</h1>
        <?php if ($running): ?>
          <span class="badge badge-success" style="font-size:0.73rem;">● Running</span>
        <?php else: ?>
          <span class="badge badge-muted" style="font-size:0.73rem;">● Stopped</span>
        <?php endif; ?>
        <?php if ($livePeers > 0): ?>
          <span class="badge badge-info" style="font-size:0.73rem;"><?= $livePeers ?> connected</span>
        <?php endif; ?>
      </div>
      <p class="page-subtitle">Head-end daemons for road-warrior access, plus site-to-site client tunnels dialling remote OpenVPN peers</p>
    </div>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
      <?= \App\Libraries\ServiceStatus::widget('openvpn') ?>
      <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openOvModal('server', null)">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
          </svg>
          Add Server
        </button>
        <button type="button" class="btn btn-primary btn-sm" onclick="openOvModal('client', null)">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
          </svg>
          Add Site-to-Site Client
        </button>
        <form method="POST" action="<?= base_url('vpn/openvpn/restart') ?>" style="display:inline;">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost btn-sm" title="Rebuild configs from the database and reload the daemon">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Reload
          </button>
        </form>
      <?php endif; ?>
      <a href="<?= base_url('certificates') ?>" class="btn btn-ghost btn-sm" title="Manage Certificate Authorities and issue OpenVPN certificates">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        PKI &amp; Certificates
      </a>
      <?php if ($isMock): ?>
        <span class="badge badge-warning">⚠ Mock Mode</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<!-- ── Tabs ── -->
<div class="ov-tabs animate-in">
  <button type="button" class="ov-tab-btn active" id="ovTabBtnServers" onclick="switchOvTab('servers')">
    Servers
    <span class="badge badge-muted" style="font-size:0.65rem; margin-left:4px;"><?= count($servers) ?></span>
  </button>
  <button type="button" class="ov-tab-btn" id="ovTabBtnClients" onclick="switchOvTab('clients')">
    Site-to-Site Clients
    <span class="badge badge-muted" style="font-size:0.65rem; margin-left:4px;"><?= count($clients) ?></span>
  </button>
</div>

<!-- ==========================================================
     TAB 1 — SERVERS (road warrior / site-to-site head end)
     ========================================================== -->
<div id="ovTabServers" class="animate-in">
  <div class="ov-section">
    <div class="ov-section-header">
      <span>Road-Warrior &amp; Head-End Daemons</span>
    </div>
    <div style="overflow-x:auto;">
      <table class="ov-table">
        <thead>
          <tr>
            <th>Instance</th>
            <th>Protocol &amp; Port</th>
            <th>Tunnel Network</th>
            <th>Cipher / Auth</th>
            <th>Auth Mode</th>
            <th>Status</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($servers)): ?>
            <tr><td colspan="7" class="ov-empty">
              No OpenVPN server defined yet — click <strong>Add Server</strong> to create a road-warrior head end.
            </td></tr>
          <?php else: ?>
            <?php foreach ($servers as $s): ?>
              <tr>
                <td>
                  <strong style="color:var(--text-primary);"><?= esc($s['name'] ?? $s['id']) ?></strong>
                  <div style="font-size:0.7rem; color:var(--text-muted); font-family:var(--font-mono);"><?= esc($s['id']) ?></div>
                </td>
                <td>
                  <span class="badge badge-muted" style="font-family:var(--font-mono);">
                    <?= esc(strtoupper($s['protocol'] ?? 'udp')) ?> : <?= (int) ($s['listen_port'] ?? 1194) ?>
                  </span>
                </td>
                <td style="font-family:var(--font-mono); color:var(--color-cyan);"><?= esc($s['tunnel_subnet'] ?? '—') ?></td>
                <td style="font-family:var(--font-mono); font-size:0.75rem;">
                  <?= esc($s['cipher'] ?? 'AES-256-GCM') ?><br>
                  <span style="color:var(--text-muted);"><?= esc($s['auth_digest'] ?? 'SHA256') ?></span>
                </td>
                <td><span class="badge badge-purple"><?= esc($s['auth_mode'] ?? 'cert_totp') ?></span></td>
                <td>
                  <?php $en = (int) ($s['enabled'] ?? 1) === 1; ?>
                  <span style="font-size:0.75rem; white-space:nowrap;">
                    <span class="ov-status-dot <?= $en ? 'ov-status-up' : 'ov-status-down' ?>"></span>
                    <?= $en ? 'Enabled' : 'Disabled' ?>
                  </span>
                </td>
                <td style="text-align:right; white-space:nowrap;">
                  <?php if ($canExport): ?>
                    <a href="<?= base_url('vpn/openvpn/export/' . esc($s['id'])) ?>" class="btn btn-ghost btn-sm" title="Download all-in-one .ovpn client bundle" style="padding:4px 8px; font-size:0.72rem;">.ovpn</a>
                    <a href="<?= base_url('vpn/openvpn/server-config/' . esc($s['id'])) ?>" class="btn btn-ghost btn-sm" title="Download compiled server.conf" style="padding:4px 8px; font-size:0.72rem;">server.conf</a>
                  <?php endif; ?>
                  <?php if ($canManage): ?>
                    <button type="button" class="btn btn-ghost btn-sm"
                            onclick='openOvModal("server", <?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>)'
                            title="Edit server" style="padding:4px 8px; font-size:0.72rem;">Edit</button>
                    <form method="POST" action="<?= base_url('vpn/openvpn/' . esc($s['id']) . '/toggle') ?>" style="display:inline;">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" title="<?= $en ? 'Disable' : 'Enable' ?>" style="padding:4px 8px; font-size:0.72rem;"><?= $en ? 'Off' : 'On' ?></button>
                    </form>
                    <form method="POST" action="<?= base_url('vpn/openvpn/' . esc($s['id']) . '/delete') ?>" style="display:inline;"
                          onsubmit="return confirm('Delete server <?= esc(addslashes($s['name'] ?? $s['id'])) ?>?');">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" title="Delete" style="color:var(--color-red); padding:4px 8px; font-size:0.72rem;">Del</button>
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
</div>

<!-- ==========================================================
     TAB 2 — SITE-TO-SITE CLIENTS
     ========================================================== -->
<div id="ovTabClients" class="animate-in" style="display:none;">
  <div class="ov-section">
    <div class="ov-section-header">
      <span>Site-to-Site Client Tunnels</span>
      <span style="font-weight:400; text-transform:none; letter-spacing:0; font-size:0.72rem; color:var(--text-muted);">
        Each row dials a remote OpenVPN server and routes its LAN across the tunnel
      </span>
    </div>
    <div style="overflow-x:auto;">
      <table class="ov-table">
        <thead>
          <tr>
            <th>Tunnel</th>
            <th>Remote Server</th>
            <th>Tunnel Addresses (local &rarr; remote)</th>
            <th>Remote Networks</th>
            <th>Crypto</th>
            <th>Status</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($clients)): ?>
            <tr><td colspan="7" class="ov-empty">
              No site-to-site tunnels yet — click <strong>Add Site-to-Site Client</strong> and enter the peer's public host,
              your tunnel address and the subnets you want routed.
            </td></tr>
          <?php else: ?>
            <?php foreach ($clients as $c): ?>
              <tr>
                <td>
                  <strong style="color:var(--text-primary);"><?= esc($c['name'] ?? $c['id']) ?></strong>
                  <div style="font-size:0.7rem; color:var(--text-muted); font-family:var(--font-mono);">
                    <?= esc($c['interface_name'] ?? 'tun0') ?> · <?= esc(strtoupper($c['protocol'] ?? 'udp')) ?>
                  </div>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--text-secondary);">
                  <?= esc($c['remote_host'] ?: '—') ?><span style="color:var(--text-muted);">:<?= (int) ($c['remote_port'] ?? 1194) ?></span>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--color-cyan);">
                  <?= esc($c['tunnel_local_ip'] ?: '—') ?>
                  <span style="color:var(--text-muted);"> &rarr; </span>
                  <?= esc($c['tunnel_remote_ip'] ?: '—') ?>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);">
                  <?= esc($c['remote_subnets'] ?: '—') ?>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.75rem;">
                  <?= esc($c['cipher'] ?? 'AES-256-GCM') ?><br>
                  <span style="color:var(--text-muted);"><?= esc($c['auth_digest'] ?? 'SHA256') ?></span>
                </td>
                <td>
                  <?php $en = (int) ($c['enabled'] ?? 1) === 1; ?>
                  <span style="font-size:0.75rem; white-space:nowrap;">
                    <span class="ov-status-dot <?= $en ? 'ov-status-up' : 'ov-status-down' ?>"></span>
                    <?= $en ? 'Enabled' : 'Disabled' ?>
                  </span>
                </td>
                <td style="text-align:right; white-space:nowrap;">
                  <?php if ($canManage): ?>
                    <a href="<?= base_url('vpn/openvpn/client-config/' . esc($c['id'])) ?>" class="btn btn-ghost btn-sm" title="Download client.conf (references /etc/openvpn/client certs)" style="padding:4px 8px; font-size:0.72rem;">client.conf</a>
                    <a href="<?= base_url('vpn/openvpn/client-config/' . esc($c['id']) . '?inline=1') ?>" class="btn btn-ghost btn-sm" title="Download client.conf with certificates embedded" style="padding:4px 8px; font-size:0.72rem;">+certs</a>
                    <a href="<?= base_url('vpn/openvpn/ccd/' . esc($c['id'])) ?>" class="btn btn-ghost btn-sm" title="Download the server-side client-config-dir entry for this peer" style="padding:4px 8px; font-size:0.72rem;">ccd</a>
                    <button type="button" class="btn btn-ghost btn-sm"
                            onclick='openOvModal("client", <?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>)'
                            title="Edit tunnel" style="padding:4px 8px; font-size:0.72rem;">Edit</button>
                    <form method="POST" action="<?= base_url('vpn/openvpn/' . esc($c['id']) . '/toggle') ?>" style="display:inline;">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" title="<?= $en ? 'Disable' : 'Enable' ?>" style="padding:4px 8px; font-size:0.72rem;"><?= $en ? 'Off' : 'On' ?></button>
                    </form>
                    <form method="POST" action="<?= base_url('vpn/openvpn/' . esc($c['id']) . '/delete') ?>" style="display:inline;"
                          onsubmit="return confirm('Delete tunnel <?= esc(addslashes($c['name'] ?? $c['id'])) ?>?');">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" title="Delete" style="color:var(--color-red); padding:4px 8px; font-size:0.72rem;">Del</button>
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
</div>

<!-- ── Tunnel interface addressing reference ── -->
<div class="ov-section animate-in" style="margin-top:24px;">
  <div class="ov-section-header"><span>Addressing — tunnel interface IPs vs. networks routed through the tunnel</span></div>
  <div style="padding:18px 20px;">

    <h4 style="font-size:0.82rem; font-weight:700; color:var(--color-cyan); margin:0 0 8px; text-transform:uppercase; letter-spacing:0.05em;">1. Tunnel interface addressing</h4>
    <p style="font-size:0.75rem; color:var(--text-muted); margin:0 0 10px; line-height:1.6;">
      The ends of the tunnel itself. This is a point-to-point pair (or a pool the server carves client addresses from) —
      it is <em>not</em> a LAN route, so it usually sits in its own unused range such as 10.8.0.0/30.
    </p>
    <table class="ov-table" style="margin-bottom:22px;">
      <thead>
        <tr>
          <th>VPN</th>
          <th>This appliance's tunnel IP</th>
          <th>Peer's / clients' tunnel IP</th>
          <th>Compiled as</th>
          <th>Where you enter it here</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong style="color:var(--text-primary);">OpenVPN server</strong></td>
          <td>First usable address of the <em>IPv4 Tunnel Network</em> (always .1)</td>
          <td>Every client from the pool (ifconfig-pool-persist)</td>
          <td><code>server 10.8.0.0 255.255.255.0</code></td>
          <td>Servers tab → <em>IPv4 Tunnel Network</em></td>
        </tr>
        <tr>
          <td><strong style="color:var(--text-primary);">OpenVPN client</strong></td>
          <td><em>Tunnel Local IP</em></td>
          <td><em>Tunnel Remote IP</em> (the remote server)</td>
          <td><code>ifconfig &lt;local&gt; &lt;remote&gt;</code>, mirrored server-side by <code>ifconfig-push &lt;peer&gt; &lt;server&gt;</code> in the ccd</td>
          <td>Site-to-Site Clients tab → <em>Tunnel Local/Remote IP</em></td>
        </tr>
        <tr>
          <td><strong style="color:var(--text-primary);">WireGuard</strong></td>
          <td><em>Interface Address</em> (e.g. 10.9.0.1/24), applied with <code>ip address add</code></td>
          <td><em>Peer Tunnel Address</em> (e.g. 10.9.0.2/32), compiled as the first AllowedIPs entry</td>
          <td><code>Address =</code> on the interface, host route in <code>AllowedIPs</code></td>
          <td>WireGuard → Tunnel <em>Interface Address</em> + peer <em>Peer Tunnel Address</em></td>
        </tr>
        <tr>
          <td><strong style="color:var(--text-primary);">IPsec (VTI)</strong></td>
          <td><em>Local Tunnel Address</em> (e.g. 10.10.1.1/30)</td>
          <td><em>Remote Tunnel Address</em> (e.g. 10.10.1.2/30)</td>
          <td><code>ip tunnel add vti0 … key &lt;if_id&gt;</code> + <code>ip addr add &lt;local&gt;/30 dev vti0</code>, tied to the SA with <code>if_id_in/out</code></td>
          <td>IPsec → <em>Route-based (VTI)</em> checkbox</td>
        </tr>
      </tbody>
    </table>

    <h4 style="font-size:0.82rem; font-weight:700; color:var(--color-indigo); margin:0 0 8px; text-transform:uppercase; letter-spacing:0.05em;">2. Networks routed through the tunnel</h4>
    <p style="font-size:0.75rem; color:var(--text-muted); margin:0 0 10px; line-height:1.6;">
      Separate from the addressing above, and the part that most often goes in backwards. In the terminology used here:
      <em>Local Network(s)</em> are behind you, <em>Remote Network(s)</em> are behind the peer.
    </p>
    <table class="ov-table" style="margin-bottom:16px;">
      <thead>
        <tr>
          <th>Field</th>
          <th>Means</th>
          <th>Becomes</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong style="color:var(--text-primary);">IPv4 Remote Network(s)</strong><br><span style="font-size:0.7rem; color:var(--text-muted);">client mode</span></td>
          <td>LANs behind the remote OpenVPN server, which this appliance must reach</td>
          <td><code>route 192.168.20.0 255.255.255.0</code> in this client's config</td>
        </tr>
        <tr>
          <td><strong style="color:var(--text-primary);">IPv4 Local Network(s)</strong><br><span style="font-size:0.7rem; color:var(--text-muted);">client mode</span></td>
          <td>LANs behind this appliance</td>
          <td><code>iroute …</code> in the exported <strong>ccd</strong> file — deploy it on the remote server so it routes your LANs down the tunnel</td>
        </tr>
        <tr>
          <td><strong style="color:var(--text-primary);">IPv4 Local Network(s)</strong><br><span style="font-size:0.7rem; color:var(--text-muted);">server mode</span></td>
          <td>LANs behind this server, offered to road-warrior clients</td>
          <td><code>push "route …"</code> to all clients</td>
        </tr>
      </tbody>
    </table>

    <p class="hint" style="font-size:0.75rem; color:var(--text-muted); margin:0 0 10px; line-height:1.6;">
      Every site-to-site peer's <em>Local Network(s)</em> is also folded into the server config as a
      <code>route</code> + <code>push "route …"</code> pair, so other clients can reach it — the same thing the generated server config does
      from each Client Specific Override. Firewall rules and outbound NAT for those networks remain a separate step under
      Firewall → Rules / NAT.
    </p>
    <p class="hint" style="font-size:0.75rem; color:var(--text-muted); margin:0; line-height:1.6;">
      <strong style="color:var(--text-secondary);">One tunnel, many networks.</strong> All three network fields above take a
      comma-separated list, so a single tunnel can carry dual-stack and multi-subnet topologies — the only fixed limits are the
      tunnel addressing itself: one <em>IPv4 Tunnel Network</em> per server daemon (OpenVPN's <code>server</code> directive accepts
      one pool) and one point-to-point address pair per client. Entries are validated on save, not silently compiled.
    </p>
  </div>
</div>

<!-- ==========================================================
     MODAL — Add / Edit OpenVPN instance (server or client)
     ========================================================== -->
<div class="ov-modal-backdrop" id="ovModal">
  <div class="ov-modal-dialog">
    <div class="ov-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="ovModalTitle">Add OpenVPN Instance</span>
      <button type="button" onclick="closeOvModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.25rem; line-height:1;">&times;</button>
    </div>

    <form method="POST" id="ovForm" action="<?= base_url('vpn/openvpn/store') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="mode" id="ovMode" value="server">
      <div class="ov-modal-body modal-scroll">

        <!-- Shared: description -->
        <div class="ov-form-row">
          <div class="ov-form-label">Description<abbr>Instance name</abbr></div>
          <div class="ov-form-control">
            <input type="text" name="name" id="ovName" class="ov-input" required maxlength="64" placeholder="e.g. hq-to-branch">
            <p class="hint">Administrative name — also used for the client-config-dir filename when acting as a server.</p>
          </div>
        </div>

        <!-- Shared: interface + protocol -->
        <div class="ov-form-row">
          <div class="ov-form-label">Interface<abbr>Tunnel device</abbr></div>
          <div class="ov-form-control">
            <input type="text" name="interface_name" id="ovIface" class="ov-input ov-input-mono ov-input-sm" value="tun0" maxlength="16">
            <p class="hint">Kernel tunnel device each instance owns (tun0, tun1, …). Must be unique per instance.</p>
          </div>
        </div>

        <div class="ov-form-row">
          <div class="ov-form-label">Protocol<abbr>Transport</abbr></div>
          <div class="ov-form-control">
            <select name="protocol" id="ovProtocol" class="ov-select ov-input-sm">
              <option value="udp">UDP</option>
              <option value="tcp">TCP</option>
            </select>
          </div>
        </div>

        <!-- ═══ SERVER ONLY ═══ -->
        <div class="ov-server-field">
          <div class="ov-form-row">
            <div class="ov-form-label">Listen Port<abbr>Local bind</abbr></div>
            <div class="ov-form-control">
              <input type="number" name="listen_port" id="ovListenPort" class="ov-input ov-input-mono ov-input-sm" value="1194" min="1" max="65535">
              <p class="hint">Port the daemon listens on. Default OpenVPN port is 1194.</p>
            </div>
          </div>

          <div class="ov-form-row">
            <div class="ov-form-label">IPv4 Tunnel Network<abbr>Client address pool</abbr></div>
            <div class="ov-form-control">
              <input type="text" name="tunnel_subnet" id="ovTunnelSubnet" class="ov-input ov-input-mono" placeholder="10.8.0.0/24">
              <p class="hint">Subnet the server hands out to road-warrior clients — this is the tunnel addressing, not a LAN route. Keep it clear of your physical networks.</p>
              <p class="hint" id="ovServerTunIp" style="font-family:var(--font-mono); color:var(--color-cyan);">This server's own tunnel IP: <span>—</span> (first usable address of the tunnel network)</p>
            </div>
          </div>

          <div class="ov-form-row">
            <div class="ov-form-label">Public Endpoint<abbr>What peers dial</abbr></div>
            <div class="ov-form-control">
              <input type="text" name="public_endpoint" id="ovPublicEndpoint" class="ov-input ov-input-mono" placeholder="vpn.example.com">
              <p class="hint">Hostname or IP embedded in generated .ovpn bundles.</p>
            </div>
          </div>

          <div class="ov-form-row">
            <div class="ov-form-label">Authentication<abbr>Verification mode</abbr></div>
            <div class="ov-form-control">
              <select name="auth_mode" id="ovAuthMode" class="ov-select">
                <option value="cert_totp">Certificate + TOTP 2FA</option>
                <option value="cert_ldap_2fa">Certificate + LDAP/AD + TOTP</option>
                <option value="cert_only">Certificate only</option>
              </select>
            </div>
          </div>
        </div>

        <!-- ═══ CLIENT ONLY ═══ -->
        <div class="ov-client-field">
          <div class="ov-form-row">
            <div class="ov-form-label">Remote Host<abbr>Peer address</abbr></div>
            <div class="ov-form-control">
              <input type="text" name="remote_host" id="ovRemoteHost" class="ov-input ov-input-mono" placeholder="vpn.branch.example.com">
              <p class="hint">Hostname or public IP of the <em>remote</em> OpenVPN server this appliance dials.</p>
            </div>
          </div>

          <div class="ov-form-row">
            <div class="ov-form-label">Remote Port<abbr>Peer port</abbr></div>
            <div class="ov-form-control">
              <input type="number" name="remote_port" id="ovRemotePort" class="ov-input ov-input-mono ov-input-sm" value="1194" min="1" max="65535">
            </div>
          </div>

        <div class="ov-form-row">
          <div class="ov-form-label">Tunnel Local IP<abbr>This appliance's tunnel IP</abbr></div>
          <div class="ov-form-control">
            <input type="text" name="tunnel_local_ip" id="ovTunnelLocal" class="ov-input ov-input-mono" placeholder="10.8.0.2">
            <p class="hint">Address handed to this end of the tunnel interface (<code>ifconfig &lt;local&gt; &lt;remote&gt;</code>) — the OpenVPN equivalent of an interface IP. No prefix length: a point-to-point pair needs none.</p>
          </div>
        </div>

          <div class="ov-form-row">
            <div class="ov-form-label">Tunnel Remote IP<abbr>Remote server</abbr></div>
            <div class="ov-form-control">
            <input type="text" name="tunnel_remote_ip" id="ovTunnelRemote" class="ov-input ov-input-mono" placeholder="10.8.0.1">
            <p class="hint">The remote server's tunnel interface address — the other half of the pair. Both ends must sit in the same unused /30, and the remote server must push it back with <code>ifconfig-push</code>.</p>
            </div>
          </div>

          <div class="ov-form-row">
            <div class="ov-form-label">IPv4 Local Network(s)<abbr>LANs behind this appliance</abbr></div>
            <div class="ov-form-control">
              <input type="text" name="local_subnets" id="ovLocalSubnetsClient" class="ov-input ov-input-mono" placeholder="192.168.1.0/24, 192.168.5.0/24" style="max-width:100%;">
              <p class="hint">Networks behind <em>this</em> appliance, comma-separated — one <code>iroute</code> line each. They are not routed here: they are exported in this tunnel's <strong>ccd</strong> file so the remote server routes them back down the tunnel.</p>
            </div>
          </div>

          <div class="ov-form-row">
            <div class="ov-form-label">IPv4 Remote Network(s)<abbr>LANs behind the peer</abbr></div>
            <div class="ov-form-control">
              <input type="text" name="remote_subnets" id="ovRemoteSubnets" class="ov-input ov-input-mono" placeholder="192.168.20.0/24, 172.16.5.0/24" style="max-width:100%;">
              <p class="hint">Every LAN reachable through the tunnel, comma-separated — one <code>route</code> line each.</p>
            </div>
          </div>

          <div class="ov-form-row">
            <div class="ov-form-label">Verify X509 Name<abbr>TLS identity check</abbr></div>
            <div class="ov-form-control">
              <input type="text" name="verify_x509_name" id="ovVerifyX509" class="ov-input ov-input-mono" placeholder="server">
              <p class="hint">Optional CN/DNS name the remote certificate must present (openvpn <code>verify-x509-name</code>).</p>
            </div>
          </div>
        </div>

        <!-- ═══ Shared crypto ═══ -->
        <div class="ov-form-row">
          <div class="ov-form-label">Encryption Cipher<abbr>Data channel</abbr></div>
          <div class="ov-form-control">
            <select name="cipher" id="ovCipher" class="ov-select ov-input-sm">
              <option value="AES-256-GCM">AES-256-GCM</option>
              <option value="AES-128-GCM">AES-128-GCM</option>
              <option value="CHACHA20-POLY1305">CHACHA20-POLY1305</option>
              <option value="AES-256-CBC">AES-256-CBC (legacy)</option>
            </select>
          </div>
        </div>

        <div class="ov-form-row">
          <div class="ov-form-label">Auth Digest<abbr>HMAC</abbr></div>
          <div class="ov-form-control">
            <select name="auth_digest" id="ovAuthDigest" class="ov-select ov-input-sm">
              <option value="SHA256">SHA256</option>
              <option value="SHA512">SHA512</option>
              <option value="SHA1">SHA1 (legacy)</option>
            </select>
          </div>
        </div>

        <div class="ov-form-row">
          <div class="ov-form-label">Compression<abbr>Optional</abbr></div>
          <div class="ov-form-control">
            <select name="compression" id="ovCompression" class="ov-select ov-input-sm">
              <option value="">None (recommended)</option>
              <option value="lz4">LZ4</option>
              <option value="lzo">LZO</option>
            </select>
          </div>
        </div>

        <div class="ov-form-row">
          <div class="ov-form-label">DNS Servers<abbr>Optional</abbr></div>
          <div class="ov-form-control">
            <input type="text" name="dns_servers" id="ovDns" class="ov-input ov-input-mono" placeholder="10.8.0.1, 1.1.1.1">
            <p class="hint">Pushed to clients. Defaults to the tunnel gateway when blank on a server.</p>
          </div>
        </div>          <div class="ov-form-row ov-server-field">
            <div class="ov-form-label">IPv4 Local Network(s)<abbr>LANs behind this server</abbr></div>
            <div class="ov-form-control">
              <input type="text" name="local_subnets" id="ovLocalSubnets" class="ov-input ov-input-mono" placeholder="192.168.1.0/24, 192.168.5.0/24" style="max-width:100%;">
              <p class="hint">Networks sitting behind this appliance — as many as you like, comma-separated. Each is pushed to clients as <code>push "route …"</code> so remote users can reach them.</p>
            </div>
          </div>

        <div class="ov-form-row">
          <div class="ov-form-label">TLS-Auth Key<abbr>HMAC firewall</abbr></div>
          <div class="ov-form-control">
            <div class="ov-toggle">
              <input type="checkbox" name="tls_auth" id="ovTlsAuth" value="1" checked>
              <span>Use ta.key (direction 0 server / 1 client)</span>
            </div>
          </div>
        </div>

        <div class="ov-form-row">
          <div class="ov-form-label">Redirect Gateway<abbr>Default route</abbr></div>
          <div class="ov-form-control">
            <div class="ov-toggle">
              <input type="checkbox" name="redirect_gateway" id="ovRedirect" value="1">
              <span>Send all traffic through the tunnel (full-tunnel). Leave off for split-tunnel site-to-site.</span>
            </div>
          </div>
        </div>

        <div class="ov-form-row" style="border-bottom:none;">
          <div class="ov-form-label">Enabled</div>
          <div class="ov-form-control">
            <div class="ov-toggle">
              <input type="checkbox" name="enabled" id="ovEnabled" value="1" checked>
              <span>Instance is active</span>
            </div>
          </div>
        </div>

      </div><!-- /.ov-modal-body -->

      <div class="ov-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeOvModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="ovSubmitBtn" style="min-width:120px;">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Tab switching ──
function switchOvTab(tab) {
  var isServers = (tab === 'servers');
  document.getElementById('ovTabServers').style.display = isServers ? '' : 'none';
  document.getElementById('ovTabClients').style.display = isServers ? 'none' : '';
  document.getElementById('ovTabBtnServers').classList.toggle('active', isServers);
  document.getElementById('ovTabBtnClients').classList.toggle('active', !isServers);
  try { localStorage.setItem('ovpnTab', tab); } catch (e) {}
}
(function () {
  var saved = null;
  try { saved = localStorage.getItem('ovpnTab'); } catch (e) {}
  if (saved === 'clients') switchOvTab('clients');
})();

// ── Modal open/close ──
function setOvMode(mode) {
  mode = (mode === 'client') ? 'client' : 'server';
  document.getElementById('ovMode').value = mode;
  document.querySelectorAll('.ov-server-field').forEach(function (el) {
    el.style.display = (mode === 'server') ? '' : 'none';
  });
  document.querySelectorAll('.ov-client-field').forEach(function (el) {
    el.style.display = (mode === 'client') ? '' : 'none';
  });
  // Disable the hidden half so its inputs are not submitted at all — several
  // fields (e.g. local_subnets) exist in both variants.
  document.querySelectorAll('.ov-server-field input, .ov-server-field select, .ov-server-field textarea')
    .forEach(function (el) { el.disabled = (mode !== 'server'); });
  document.querySelectorAll('.ov-client-field input, .ov-client-field select, .ov-client-field textarea')
    .forEach(function (el) { el.disabled = (mode !== 'client'); });
  document.getElementById('ovModalTitle').innerText =
    (mode === 'client' ? 'Site-to-Site Client' : 'OpenVPN Server') + ' — ' +
    (document.getElementById('ovForm').dataset.editing === '1' ? 'Edit' : 'Add');
  document.getElementById('ovSubmitBtn').innerText =
    document.getElementById('ovForm').dataset.editing === '1' ? 'Update & Reload' : 'Save & Reload';
}

function openOvModal(mode, data) {
  var form = document.getElementById('ovForm');
  var editing = !!data;

  form.dataset.editing = editing ? '1' : '0';
  form.action = editing
    ? '<?= base_url('vpn/openvpn') ?>/' + encodeURIComponent(data.id) + '/update'
    : '<?= base_url('vpn/openvpn/store') ?>';

  var v = function (key, fallback) {
    var val = (data && data[key] !== null && data[key] !== undefined && data[key] !== '') ? data[key] : fallback;
    return (val === undefined) ? '' : val;
  };
  var on = function (key, fallback) {
    if (!data) return fallback;
    return parseInt(data[key]) === 1;
  };

  document.getElementById('ovName').value         = v('name', '');
  document.getElementById('ovIface').value        = v('interface_name', 'tun0');
  document.getElementById('ovProtocol').value     = v('protocol', 'udp');

  document.getElementById('ovListenPort').value   = v('listen_port', 1194);
  document.getElementById('ovTunnelSubnet').value = v('tunnel_subnet', '10.8.0.0/24');
  document.getElementById('ovPublicEndpoint').value = v('public_endpoint', '');
  document.getElementById('ovAuthMode').value     = v('auth_mode', 'cert_totp');

  document.getElementById('ovRemoteHost').value   = v('remote_host', '');
  document.getElementById('ovRemotePort').value   = v('remote_port', 1194);
  document.getElementById('ovTunnelLocal').value  = v('tunnel_local_ip', '');
  document.getElementById('ovTunnelRemote').value = v('tunnel_remote_ip', '');
  document.getElementById('ovRemoteSubnets').value= v('remote_subnets', '');
  document.getElementById('ovVerifyX509').value   = v('verify_x509_name', '');

  document.getElementById('ovCipher').value       = v('cipher', 'AES-256-GCM');
  document.getElementById('ovAuthDigest').value   = v('auth_digest', 'SHA256');
  document.getElementById('ovCompression').value  = v('compression', '');
  document.getElementById('ovDns').value          = v('dns_servers', '');

  // "IPv4 Local Network(s)" is stored in routes_pushed for servers and in
  // local_subnets for clients
  var localNets = v('local_subnets', v('routes_pushed', ''));
  document.getElementById('ovLocalSubnets').value       = localNets;
  document.getElementById('ovLocalSubnetsClient').value = v('local_subnets', '');

  updateServerTunnelIp();

  document.getElementById('ovTlsAuth').checked    = on('tls_auth', true);
  document.getElementById('ovRedirect').checked   = on('redirect_gateway', false);
  document.getElementById('ovEnabled').checked    = on('enabled', true);

  setOvMode(mode || (data && data.mode) || 'server');
  document.getElementById('ovModal').classList.add('active');

  // Reset AFTER the modal is displayed: a scroll container that is still
  // display:none ignores scrollTop, and the browser would restore the
  // position from the previous open.
  document.querySelector('#ovModal .ov-modal-body').scrollTop = 0;
}

// ── Derived trip: the server always takes the first usable address of the
// tunnel network (always the first usable address), so show what that will be.
function updateServerTunnelIp() {
  var out = document.querySelector('#ovServerTunIp span');
  if (!out) return;

  var raw = (document.getElementById('ovTunnelSubnet').value || '').trim();
  var m   = raw.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\/(\d{1,2})$/);
  if (!m) { out.textContent = '—'; return; }

  var parts = [m[1], m[2], m[3], m[4]].map(Number);
  if (parts.some(function (n) { return n > 255; }) || Number(m[5]) > 32) { out.textContent = '—'; return; }

  var base = ((parts[0] << 24) | (parts[1] << 16) | (parts[2] << 8) | parts[3]) >>> 0;
  var mask = Number(m[5]) === 0 ? 0 : (0xFFFFFFFF << (32 - Number(m[5]))) >>> 0;
  var server = (((base & mask) >>> 0) + 1) >>> 0;

  out.textContent = [server >>> 24, (server >>> 16) & 255, (server >>> 8) & 255, server & 255].join('.');
}

document.getElementById('ovTunnelSubnet').addEventListener('input', updateServerTunnelIp);

function closeOvModal() {
  document.getElementById('ovModal').classList.remove('active');
}
document.getElementById('ovModal').addEventListener('click', function (e) {
  if (e.target === this) closeOvModal();
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') closeOvModal();
});
</script>

<?= $this->endSection() ?>
