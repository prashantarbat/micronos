<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <h1 class="page-title">IPsec & IKEv2 VPN</h1>
      <p class="page-subtitle">Enterprise Site-to-Site VPN tunnels, route-based VTI, and native IKEv2 mobile road warrior pools</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <?= \App\Libraries\ServiceStatus::widget('strongswan') ?>
      <a href="<?= base_url('vpn/ipsec/config') ?>" class="btn btn-ghost" title="Download the compiled /etc/swanctl/swanctl.conf">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        swanctl.conf
      </a>
      <a href="<?= base_url('certificates') ?>" class="btn btn-ghost" title="Manage Certificate Authorities and issue X.509 RSA/ECDSA machine certificates">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        PKI &amp; Certificates
      </a>
      <?php if (session()->get('is_superadmin') || in_array('vpn.ipsec.manage', session()->get('permissions') ?? [])): ?>
        <form method="POST" action="<?= base_url('vpn/ipsec/restart') ?>">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Restart strongSwan
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Metrics Row -->
<div class="stats-grid animate-in animate-in-delay-1" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom:24px;">
  <div class="stat-card cyan">
    <div class="stat-icon cyan">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
      </svg>
    </div>
    <div class="stat-value"><?= count($tunnels) ?></div>
    <div class="stat-label">Defined Tunnels</div>
  </div>

  <div class="stat-card emerald">
    <div class="stat-icon emerald">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
      </svg>
    </div>
    <div class="stat-value">AES-256-GCM</div>
    <div class="stat-label">Hardware Crypto Engine</div>
  </div>

  <div class="stat-card purple">
    <div class="stat-icon purple">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
      </svg>
    </div>
    <div class="stat-value">IKEv2 / strongSwan</div>
    <div class="stat-label">IPsec Daemon Framework</div>
  </div>
</div>

<!-- Tunnels List -->
<div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:32px;">
  <div class="card-header">
    <span class="card-title">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
      </svg>
      Configured IPsec Tunnels & Security Associations (SAs)
    </span>
    <span class="badge badge-success">Daemon Active (IKEv2 / strongSwan)</span>
  </div>

  <div style="overflow-x:auto;">
    <table class="iface-table">
      <thead>
        <tr>
          <th>Tunnel Name</th>
          <th>Version & Mode</th>
          <th>Remote Gateway</th>
          <th>Local Subnet</th>
          <th>Remote Subnet</th>
          <th>Tunnel Interface</th>
          <th>Encryption (IKE / ESP)</th>
          <th>Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($tunnels)): ?>
          <tr>
            <td colspan="9" style="text-align:center; padding:32px; color:var(--text-muted);">
              No IPsec tunnels configured. Create a Site-to-Site or Road Warrior tunnel below.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($tunnels as $t): ?>
            <tr>
              <td>
                <div style="font-weight:600; font-size:0.92rem; color:var(--text-primary);"><?= esc($t['name']) ?></div>
                <div style="font-family:var(--font-mono); font-size:0.73rem; color:var(--color-cyan);"><?= esc($t['id']) ?></div>
              </td>
              <td>
                <span class="badge badge-purple" style="font-family:var(--font-mono); font-size:0.7rem;"><?= esc(strtoupper($t['ike_version'])) ?></span>
                <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">
                  <?= ($t['mode'] === 'site2site') ? 'Site-to-Site' : 'Road Warrior' ?>
                </div>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.82rem; color:var(--text-primary);">
                <?= esc($t['remote_gateway'] ?: 'Dynamic / Mobile') ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--color-cyan);">
                <?= esc($t['local_subnet']) ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--color-emerald);">
                <?= esc($t['remote_subnet'] ?: '0.0.0.0/0 (Split Tunnel)') ?>
              </td>
              <td>
                <?php if ((int) ($t['vti_enabled'] ?? 0) === 1): ?>
                  <span class="badge badge-info" style="font-family:var(--font-mono); font-size:0.68rem;"><?= esc($t['vti_interface'] ?: 'vti0') ?></span>
                  <div style="font-family:var(--font-mono); font-size:0.72rem; color:var(--color-cyan); margin-top:3px;">
                    <?= esc($t['vti_local_ip'] ?: '—') ?> &rarr; <?= esc($t['vti_remote_ip'] ?: '—') ?>
                  </div>
                  <div style="font-size:0.68rem; color:var(--text-muted);">route-based · if_id <?= (int) ($t['vti_mark'] ?? 1) ?></div>
                <?php else: ?>
                  <span class="badge badge-muted" style="font-size:0.68rem;">Policy-based</span>
                  <div style="font-size:0.68rem; color:var(--text-muted); margin-top:3px;">No tunnel IP — the subnets above <em>are</em> the tunnel</div>
                <?php endif; ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.72rem; color:var(--text-secondary);">
                <div>IKE: <?= esc($t['ike_cipher']) ?></div>
                <div>ESP: <?= esc($t['esp_cipher']) ?></div>
              </td>
              <td>
                <span class="badge badge-success">
                  <span style="width:6px; height:6px; border-radius:50%; background:var(--color-emerald); display:inline-block;"></span>
                  Established (SA Active)
                </span>
              </td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ((int) ($t['vti_enabled'] ?? 0) === 1): ?>
                  <a href="<?= base_url('vpn/ipsec/' . esc($t['id']) . '/vti-script') ?>"
                     class="btn btn-ghost btn-sm" title="Download the VTI bring-up script (ip tunnel add / ip addr add)"
                     style="padding:4px 8px; font-size:0.72rem;">vti.sh</a>
                <?php endif; ?>
                <?php if (session()->get('is_superadmin') || in_array('vpn.ipsec.manage', session()->get('permissions') ?? [])): ?>
                  <!-- Toggle enabled -->
                  <form method="POST" action="<?= base_url('vpn/ipsec/' . esc($t['id']) . '/toggle') ?>" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" title="<?= (int)($t['enabled'] ?? 1) === 1 ? 'Disable Tunnel' : 'Enable Tunnel' ?>" style="color:<?= (int)($t['enabled'] ?? 1) === 1 ? 'var(--color-emerald)' : 'var(--text-muted)' ?>; padding:4px 8px;">
                      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M12 3v9"/>
                      </svg>
                    </button>
                  </form>

                  <!-- Edit Tunnel -->
                  <button type="button" class="btn btn-ghost btn-sm" onclick='openEditIpsecModal(<?= json_encode($t, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Edit Tunnel" style="padding:4px 8px;">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </button>

                  <!-- Delete Tunnel -->
                  <form method="POST" action="<?= base_url('vpn/ipsec/' . esc($t['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Delete IPsec tunnel <?= esc(addslashes($t['name'])) ?>?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger btn-sm" title="Delete Tunnel" style="padding:4px 8px;">
                      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
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

<!-- Add Tunnel Card -->
<?php if (session()->get('is_superadmin') || in_array('vpn.ipsec.manage', session()->get('permissions') ?? [])): ?>
  <div class="card card-glass animate-in animate-in-delay-3">
    <div class="card-header">
      <span class="card-title">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Add IPsec / IKEv2 Tunnel
      </span>
    </div>
    <div class="card-body">
      <form method="POST" action="<?= base_url('vpn/ipsec/store') ?>">
        <?= csrf_field() ?>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:16px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="tunnel_name">Tunnel Name <span style="color:var(--color-red);">*</span></label>
            <input type="text" name="name" id="tunnel_name" class="form-control" required placeholder="e.g. Branch-Chicago-IPsec">
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="ike_version">IKE Protocol Version</label>
            <select name="ike_version" id="ike_version" class="form-control" style="background:var(--color-surface-1);">
              <option value="ikev2" selected>IKEv2 (Modern, Fast Re-keying, Recommended)</option>
              <option value="ikev1">IKEv1 (Legacy Compatibility Mode)</option>
            </select>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="mode">Tunnel Mode</label>
            <select name="mode" id="mode" class="form-control" style="background:var(--color-surface-1);">
              <option value="site2site" selected>Site-to-Site (LAN-to-LAN Tunnel)</option>
              <option value="roadwarrior">Mobile / Road Warrior (IKEv2 EAP / Radius)</option>
            </select>
          </div>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:16px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="remote_gateway">Remote Gateway IP / FQDN</label>
            <input type="text" name="remote_gateway" id="remote_gateway" class="form-control"
                   placeholder="e.g. 203.0.113.50 (Leave blank for mobile clients)" style="font-family:var(--font-mono);">
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="local_subnet">Local Protected Network(s)</label>
            <input type="text" name="local_subnet" id="local_subnet" class="form-control"
                   value="192.168.1.0/24" placeholder="192.168.1.0/24, 192.168.5.0/24" style="font-family:var(--font-mono);">
            <p style="font-size:0.7rem; color:var(--text-muted); margin:4px 0 0;">One or many subnets behind this appliance, comma-separated → <code>local_ts</code>.</p>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="remote_subnet">Remote Network(s)</label>
            <input type="text" name="remote_subnet" id="remote_subnet" class="form-control"
                   placeholder="e.g. 10.50.0.0/16, 10.60.0.0/16" style="font-family:var(--font-mono);">
            <p style="font-size:0.7rem; color:var(--text-muted); margin:4px 0 0;">Subnets behind the peer, comma-separated → <code>remote_ts</code> (and one route each on a VTI).</p>
          </div>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:16px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="psk_secret">Pre-Shared Key (PSK)</label>
            <input type="password" name="psk_secret" id="psk_secret" class="form-control" autocomplete="new-password" spellcheck="false"
                   placeholder="••••••••••••••••••••" style="font-family:var(--font-mono);">
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="ike_cipher">Phase 1 (IKE SA) Proposal</label>
            <select name="ike_cipher" id="ike_cipher" class="form-control" style="background:var(--color-surface-1); font-family:var(--font-mono); font-size:0.8rem;">
              <option value="aes256gcm16-prfsha384-ecp384" selected>AES-256-GCM / SHA-384 / DH-19 (ECDH-384)</option>
              <option value="aes256-sha256-modp2048">AES-256-CBC / SHA-256 / DH-14 (MODP-2048)</option>
              <option value="chacha20poly1305-prfsha512-curve25519">ChaCha20-Poly1305 / SHA-512 / Curve25519</option>
            </select>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="esp_cipher">Phase 2 (Child SA / ESP) Proposal</label>
            <select name="esp_cipher" id="esp_cipher" class="form-control" style="background:var(--color-surface-1); font-family:var(--font-mono); font-size:0.8rem;">
              <option value="aes256gcm16-ecp384" selected>AES-256-GCM / PFS DH-19</option>
              <option value="aes256-sha256-modp2048">AES-256-CBC / SHA-256 / PFS DH-14</option>
              <option value="chacha20poly1305-curve25519">ChaCha20-Poly1305 / Curve25519</option>
            </select>
          </div>
        </div>

        <!-- Route-based (VTI) tunnel interface -->
        <div style="border:1px solid var(--color-border); border-radius:var(--radius-md); padding:14px 16px; margin-bottom:4px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.85rem; color:var(--text-primary); font-weight:600; margin:0 0 4px;">
            <input type="checkbox" name="vti_enabled" id="vti_enabled" value="1" style="accent-color:var(--color-cyan); width:16px; height:16px;">
            Route-based (VTI) — give this tunnel an interface and its own IP addresses
          </label>
          <p style="font-size:0.72rem; color:var(--text-muted); margin:0 0 12px; line-height:1.5;">
            Leave unticked for a policy-based tunnel, where the subnets above are the tunnel and no interface or tunnel IP exists.
            Tick it to create a VTI with an address pair, then download the generated bring-up script from the tunnel's row.
          </p>

          <div id="vtiFields" style="display:none; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="vti_interface">VTI Interface</label>
              <input type="text" name="vti_interface" id="vti_interface" class="form-control" value="vti0" style="font-family:var(--font-mono);">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="vti_local_ip">Local Tunnel Address (this appliance)</label>
              <input type="text" name="vti_local_ip" id="vti_local_ip" class="form-control" placeholder="10.10.1.1" style="font-family:var(--font-mono);">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="vti_remote_ip">Remote Tunnel Address (peer)</label>
              <input type="text" name="vti_remote_ip" id="vti_remote_ip" class="form-control" placeholder="10.10.1.2" style="font-family:var(--font-mono);">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="vti_mark">if_id / VTI Key</label>
              <input type="number" name="vti_mark" id="vti_mark" class="form-control" value="1" min="1" max="65535">
            </div>
          </div>
        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:20px;">
          <button type="submit" class="btn btn-primary" style="min-width:140px;">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
            </svg>
            Save IPsec Tunnel
          </button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<!-- ============================================================
     Edit IPsec Tunnel Modal
============================================================ -->
<div id="editIpsecModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(6px); z-index:9999; align-items:center; justify-content:center; padding:16px;">
  <div style="background:var(--color-surface-2); border:1px solid var(--color-border-bright); border-radius:var(--radius-lg); max-width:760px; width:100%; box-shadow:var(--shadow-lg); overflow:hidden; max-height:90vh; display:flex; flex-direction:column;">
    <div style="padding:16px 24px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-size:1.05rem; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
        </svg>
        Edit IPsec / IKEv2 Tunnel
      </h3>
      <button type="button" onclick="closeEditIpsecModal()" class="btn btn-ghost btn-sm" style="font-size:1.2rem; line-height:1; padding:4px 8px;">&times;</button>
    </div>

    <form id="editIpsecForm" method="POST" action="" class="modal-scroll"
          style="padding:24px; display:flex; flex-direction:column; gap:16px; flex:1 1 auto; min-height:0;">
      <?= csrf_field() ?>

      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="edit_tunnel_name">Tunnel Name</label>
          <input type="text" name="name" id="edit_tunnel_name" class="form-control" required>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="edit_ike_version">IKE Protocol Version</label>
          <select name="ike_version" id="edit_ike_version" class="form-control">
            <option value="ikev2">IKEv2 (Modern, Recommended)</option>
            <option value="ikev1">IKEv1 (Legacy Interop)</option>
          </select>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="edit_tunnel_mode">Connection Mode</label>
          <select name="mode" id="edit_tunnel_mode" class="form-control">
            <option value="site2site">Site-to-Site Gateway</option>
            <option value="roadwarrior">Road Warrior (Remote Access)</option>
          </select>
        </div>
      </div>

      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="edit_remote_gw">Remote Gateway IP / FQDN</label>
          <input type="text" name="remote_gateway" id="edit_remote_gw" class="form-control" placeholder="vpn.partner.com or 203.0.113.1" style="font-family:var(--font-mono);">
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="edit_psk">Pre-Shared Key (PSK)</label>
          <input type="password" name="psk_secret" id="edit_psk" class="form-control" placeholder="Leave blank to keep existing" autocomplete="new-password" spellcheck="false" style="font-family:var(--font-mono);">
        </div>
      </div>

      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="edit_local_sub">Local Protected Network(s)</label>
          <input type="text" name="local_subnet" id="edit_local_sub" class="form-control" required placeholder="192.168.1.0/24, 192.168.5.0/24" style="font-family:var(--font-mono);">
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="edit_remote_sub">Remote Network(s)</label>
          <input type="text" name="remote_subnet" id="edit_remote_sub" class="form-control" placeholder="10.50.0.0/16, 10.60.0.0/16" style="font-family:var(--font-mono);">
        </div>
      </div>

      <!-- IKE identities + route-based (VTI) tunnel interface -->
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="edit_local_gw">Local Gateway (source for IKE)</label>
          <input type="text" name="local_gateway" id="edit_local_gw" class="form-control" placeholder="Blank = default route" style="font-family:var(--font-mono);">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="edit_local_id">Local IKE Identity</label>
          <input type="text" name="local_id" id="edit_local_id" class="form-control" placeholder="e.g. @micronos.local" style="font-family:var(--font-mono);">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="edit_remote_id">Remote IKE Identity</label>
          <input type="text" name="remote_id" id="edit_remote_id" class="form-control" placeholder="e.g. @partner.example" style="font-family:var(--font-mono);">
        </div>
      </div>

      <div style="border:1px solid var(--color-border); border-radius:var(--radius-md); padding:14px 16px;">
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.85rem; color:var(--text-primary); font-weight:600; margin:0 0 10px;">
          <input type="checkbox" name="vti_enabled" id="edit_vti_enabled" value="1" style="accent-color:var(--color-cyan); width:16px; height:16px;">
          Route-based (VTI) tunnel interface
        </label>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:16px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="edit_vti_interface">VTI Interface</label>
            <input type="text" name="vti_interface" id="edit_vti_interface" class="form-control" style="font-family:var(--font-mono);">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="edit_vti_local">Local Tunnel Address</label>
            <input type="text" name="vti_local_ip" id="edit_vti_local" class="form-control" style="font-family:var(--font-mono);">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="edit_vti_remote">Remote Tunnel Address</label>
            <input type="text" name="vti_remote_ip" id="edit_vti_remote" class="form-control" style="font-family:var(--font-mono);">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="edit_vti_mark">if_id / VTI Key</label>
            <input type="number" name="vti_mark" id="edit_vti_mark" class="form-control" min="1" max="65535">
          </div>
        </div>
      </div>

      <div style="display:flex; align-items:center; gap:6px; padding-top:4px;">
        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.85rem; color:var(--text-secondary); margin:0;">
          <input type="checkbox" name="enabled" id="edit_tunnel_enabled" value="1" style="accent-color:var(--color-emerald); width:16px; height:16px;">
          Tunnel Enabled
        </label>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:12px;">
        <button type="button" onclick="closeEditIpsecModal()" class="btn btn-ghost">Cancel</button>
        <button type="submit" class="btn btn-primary" style="min-width:140px;">Update Tunnel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditIpsecModal(tunnel) {
  const modal = document.getElementById('editIpsecModal');
  const form = document.getElementById('editIpsecForm');
  form.action = '<?= base_url('vpn/ipsec') ?>/' + encodeURIComponent(tunnel.id) + '/update';

  document.getElementById('edit_tunnel_name').value = tunnel.name || '';
  document.getElementById('edit_ike_version').value = tunnel.ike_version || 'ikev2';
  document.getElementById('edit_tunnel_mode').value = tunnel.mode || 'site2site';
  document.getElementById('edit_remote_gw').value = tunnel.remote_gateway || '';
  document.getElementById('edit_psk').value = '';
  document.getElementById('edit_local_sub').value = tunnel.local_subnet || '192.168.1.0/24';
  document.getElementById('edit_remote_sub').value = tunnel.remote_subnet || '';
  document.getElementById('edit_tunnel_enabled').checked = (parseInt(tunnel.enabled) !== 0);

  document.getElementById('edit_local_gw').value  = tunnel.local_gateway || '';
  document.getElementById('edit_local_id').value  = tunnel.local_id || '';
  document.getElementById('edit_remote_id').value = tunnel.remote_id || '';

  var routeBased = parseInt(tunnel.vti_enabled) === 1;
  document.getElementById('edit_vti_enabled').checked   = routeBased;
  document.getElementById('edit_vti_interface').value   = tunnel.vti_interface || 'vti0';
  document.getElementById('edit_vti_local').value       = tunnel.vti_local_ip || '';
  document.getElementById('edit_vti_remote').value      = tunnel.vti_remote_ip || '';
  document.getElementById('edit_vti_mark').value        = tunnel.vti_mark || 1;

  modal.style.display = 'flex';
  // Reset AFTER display: a hidden scroll container ignores scrollTop.
  form.scrollTop = 0;
}

function closeEditIpsecModal() {
  document.getElementById('editIpsecModal').style.display = 'none';
}

// Reveal the tunnel-address fields only when the tunnel is route-based
(function () {
  var toggle = document.getElementById('vti_enabled');
  var fields = document.getElementById('vtiFields');
  if (toggle && fields) {
    toggle.addEventListener('change', function () {
      fields.style.display = toggle.checked ? 'grid' : 'none';
    });
  }
})();

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeEditIpsecModal();
});
</script>

<?= $this->endSection() ?>
