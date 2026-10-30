<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <span>Network</span>
      <span class="sep">/</span>
      <span class="current">Captive Portal</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-amber">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
        </svg>
      </div>
      Captive Portal &amp; Hotspot Engine
    </h1>
    <p class="page-subtitle">Kernel-level Netfilter interception, time/quota voucher generator, guest self-service splash, and walled garden domains.</p>
  </div>
  <div class="page-header-actions">
    <a href="<?= base_url('captive/vouchers') ?>" class="btn btn-secondary btn-sm">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/></svg>
      Vouchers (<?= (int)$totalVouchers ?>)
    </a>
    <a href="<?= base_url('captive/sessions') ?>" class="btn btn-secondary btn-sm">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
      Live Sessions (<?= (int)$activeSessions ?>)
    </a>
    <form method="POST" action="<?= base_url('captive/apply') ?>" style="margin:0; display:inline;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary btn-sm">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        Apply &amp; Reload NFTables
      </button>
    </form>
  </div>
</div>

<?php if ((session()->getFlashdata('success') ?? session()->getFlashdata('message'))): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:16px;">
    <?= esc((session()->getFlashdata('success') ?? session()->getFlashdata('message'))) ?>
  </div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:16px;">
    <?= esc(session()->getFlashdata('error')) ?>
  </div>
<?php endif; ?>

<!-- Quick Stat Cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:20px;">
  <div class="card card-glass animate-in" style="padding:16px; border-left:3px solid var(--color-amber);">
    <div style="font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); font-weight:700;">Managed Zones</div>
    <div style="font-size:1.6rem; font-weight:800; color:var(--text-primary); margin-top:4px;"><?= count($zones) ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">Network interfaces isolated</div>
  </div>
  <div class="card card-glass animate-in" style="padding:16px; border-left:3px solid var(--color-emerald);">
    <div style="font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); font-weight:700;">Active Sessions</div>
    <div style="font-size:1.6rem; font-weight:800; color:var(--color-emerald); margin-top:4px;"><?= (int)$activeSessions ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">Authenticated guest clients</div>
  </div>
  <div class="card card-glass animate-in" style="padding:16px; border-left:3px solid var(--color-cyan);">
    <div style="font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); font-weight:700;">Available Vouchers</div>
    <div style="font-size:1.6rem; font-weight:800; color:var(--color-cyan); margin-top:4px;"><?= (int)$totalVouchers ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">Active &amp; unused codes</div>
  </div>
  <div class="card card-glass animate-in" style="padding:16px; border-left:3px solid var(--color-purple);">
    <div style="font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); font-weight:700;">Interception Engine</div>
    <div style="font-size:1.1rem; font-weight:800; color:var(--text-primary); margin-top:8px;">NFTables Kernel Sets</div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">Dynamic port 8088/8443 redirect</div>
  </div>
</div>

<!-- Zones Management Card -->
<div class="card card-glass animate-in">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <div>
      <span class="card-title">Hotspot Access Zones</span>
      <span class="card-subtitle" style="display:block; font-size:0.75rem; color:var(--text-muted);">Configure client interception per physical interface, bridge, or VLAN.</span>
    </div>
    <button type="button" class="btn btn-primary btn-sm" onclick="openZoneModal()">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
      Add Hotspot Zone
    </button>
  </div>

  <div class="table-responsive">
    <table class="table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr>
          <th>Zone Name &amp; Slug</th>
          <th>Interface</th>
          <th>Auth Mode</th>
          <th>Quotas / Limit</th>
          <th>Redirection</th>
          <th>Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($zones)): ?>
          <tr>
            <td colspan="7" style="text-align:center; padding:32px; color:var(--text-muted);">
              No captive zones defined yet. Click <strong>"Add Hotspot Zone"</strong> to protect a guest interface.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($zones as $z): ?>
            <tr>
              <td>
                <div style="font-weight:700; color:var(--text-primary);"><?= esc($z['name']) ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted); font-family:var(--font-mono);"><?= esc($z['slug']) ?></div>
              </td>
              <td>
                <span class="badge" style="background:var(--color-surface-2); border:1px solid var(--color-border); font-family:var(--font-mono);">
                  <?= esc($z['interface']) ?>
                </span>
              </td>
              <td>
                <span class="badge" style="text-transform:uppercase; font-size:0.7rem; font-weight:700; background:<?= $z['auth_mode'] === 'voucher' ? 'rgba(245,158,11,0.15); color:#f59e0b' : 'rgba(16,185,129,0.15); color:#10b981' ?>;">
                  <?= esc($z['auth_mode']) ?>
                </span>
              </td>
              <td style="font-size:0.75rem; color:var(--text-secondary);">
                <div>⏳ Session: <?= (int)$z['session_timeout'] ?>m | Idle: <?= (int)$z['idle_timeout'] ?>m</div>
                <div>⚡ Up: <?= (int)$z['rate_limit_up_kbps'] ?>k | Down: <?= (int)$z['rate_limit_down_kbps'] ?>k</div>
                <div>📱 Max Devices: <?= (int)$z['concurrent_devices'] ?></div>
              </td>
              <td style="font-size:0.75rem; font-family:var(--font-mono); color:var(--text-muted);">
                HTTP: <?= (int)$z['http_port'] ?><br>HTTPS: <?= (int)$z['https_port'] ?>
              </td>
              <td>
                <form method="POST" action="<?= base_url('captive/zone/' . $z['id'] . '/toggle') ?>" style="margin:0; display:inline;">
                  <?= csrf_field() ?>
                  <button type="submit" class="badge" style="cursor:pointer; border:none; padding:4px 8px; border-radius:4px; font-weight:700; background:<?= $z['enabled'] ? 'rgba(16,185,129,0.2); color:#10b981;' : 'rgba(239,68,68,0.2); color:#ef4444;' ?>">
                    <?= $z['enabled'] ? '● ENABLED' : '○ DISABLED' ?>
                  </button>
                </form>
              </td>
              <td style="text-align:right;">
                <div style="display:inline-flex; gap:6px;">
                  <a href="<?= base_url('portal/auth/' . $z['slug']) ?>" target="_blank" class="btn btn-secondary btn-sm" title="Preview Guest Splash" style="padding:4px 8px;">
                    👁 Splash
                  </a>
                  <a href="<?= base_url('captive/branding/' . $z['id']) ?>" class="btn btn-secondary btn-sm" title="Customize Portal Branding" style="padding:4px 8px;">
                    🎨 Branding
                  </a>
                  <button type="button" class="btn btn-secondary btn-sm" onclick='editZone(<?= json_encode($z) ?>)' style="padding:4px 8px;">
                    ✎ Edit
                  </button>
                  <form method="POST" action="<?= base_url('captive/zone/' . $z['id'] . '/delete') ?>" style="margin:0; display:inline;" onsubmit="return confirm('Delete zone <?= esc($z['name']) ?>?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger btn-sm" style="padding:4px 8px;">✕</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Zone Add/Edit Modal -->
<div id="zoneModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); z-index:999; align-items:center; justify-content:center; padding:16px;">
  <div class="card card-glass" style="max-width:620px; width:100%; max-height:90vh; overflow-y:auto; border:1px solid var(--color-border); box-shadow:0 12px 36px rgba(0,0,0,0.5);">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="card-title" id="zoneModalTitle">Add Hotspot Zone</span>
      <button type="button" class="btn-close" onclick="closeZoneModal()" style="background:transparent; border:none; color:var(--text-muted); font-size:1.2rem; cursor:pointer;">✕</button>
    </div>
    <form method="POST" action="<?= base_url('captive/zone/save') ?>" id="zoneForm" style="padding:18px;">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="z_id" value="">

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:12px;">
        <div>
          <label class="form-label">Zone Name <span style="color:var(--color-rose);">*</span></label>
          <input type="text" name="name" id="z_name" class="form-control" placeholder="Guest WiFi" required onkeyup="syncSlug()">
        </div>
        <div>
          <label class="form-label">Slug <span style="color:var(--color-rose);">*</span></label>
          <input type="text" name="slug" id="z_slug" class="form-control" placeholder="guest-wifi" required style="font-family:var(--font-mono);">
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:12px;">
        <div>
          <label class="form-label">Captive Interface <span style="color:var(--color-rose);">*</span></label>
          <select name="interface" id="z_interface" class="form-control" required>
            <?php foreach ($interfaces as $iface): ?>
              <?php
                $val   = $iface['id'] ?? ($iface['name'] ?? '');
                $name  = $iface['name'] ?? $val;
                $dev   = $iface['physical_device'] ?? '';
                $label = $iface['label'] ?? (($dev !== '' && $dev !== $name) ? "{$name} ({$dev})" : $name);
              ?>
              <option value="<?= esc($val) ?>"><?= esc($label) ?></option>
            <?php endforeach; ?>
          </select>
          <small style="font-size:0.7rem; color:var(--text-muted);">Interface whose traffic will be redirected.</small>
        </div>
        <div>
          <label class="form-label">Authentication Mode</label>
          <select name="auth_mode" id="z_auth_mode" class="form-control">
            <option value="voucher">Voucher Code (Hotspot Roll)</option>
            <option value="clickthrough">Click-through Terms of Service</option>
            <option value="radius">External RADIUS AAA</option>
          </select>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:12px;">
        <div>
          <label class="form-label">Session Timeout (m)</label>
          <input type="number" name="session_timeout" id="z_session_timeout" class="form-control" value="120" min="1" max="43200">
        </div>
        <div>
          <label class="form-label">Idle Timeout (m)</label>
          <input type="number" name="idle_timeout" id="z_idle_timeout" class="form-control" value="30" min="1" max="1440">
        </div>
        <div>
          <label class="form-label">Max Devices</label>
          <input type="number" name="concurrent_devices" id="z_concurrent_devices" class="form-control" value="1" min="1" max="20">
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:12px;">
        <div>
          <label class="form-label">Download Rate (kbps)</label>
          <input type="number" name="rate_limit_down_kbps" id="z_rate_limit_down_kbps" class="form-control" value="0" min="0">
          <small style="font-size:0.7rem; color:var(--text-muted);">0 = Unmetered link</small>
        </div>
        <div>
          <label class="form-label">Upload Rate (kbps)</label>
          <input type="number" name="rate_limit_up_kbps" id="z_rate_limit_up_kbps" class="form-control" value="0" min="0">
          <small style="font-size:0.7rem; color:var(--text-muted);">0 = Unmetered link</small>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
        <div>
          <label class="form-label">HTTP Intercept Port</label>
          <input type="number" name="http_port" id="z_http_port" class="form-control" value="8088">
        </div>
        <div>
          <label class="form-label">HTTPS Intercept Port</label>
          <input type="number" name="https_port" id="z_https_port" class="form-control" value="8443">
        </div>
      </div>

      <div style="margin-bottom:16px;">
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.85rem; font-weight:600; color:var(--text-primary);">
          <input type="checkbox" name="enabled" id="z_enabled" value="1" checked style="accent-color:var(--color-amber);">
          Enable this Hotspot Zone immediately
        </label>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="closeZoneModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save Zone</button>
      </div>
    </form>
  </div>
</div>

<script>
function openZoneModal() {
  document.getElementById('zoneModalTitle').textContent = 'Add Hotspot Zone';
  document.getElementById('z_id').value = '';
  document.getElementById('z_name').value = '';
  document.getElementById('z_slug').value = '';
  document.getElementById('z_auth_mode').value = 'voucher';
  document.getElementById('z_session_timeout').value = '120';
  document.getElementById('z_idle_timeout').value = '30';
  document.getElementById('z_concurrent_devices').value = '1';
  document.getElementById('z_rate_limit_down_kbps').value = '0';
  document.getElementById('z_rate_limit_up_kbps').value = '0';
  document.getElementById('z_http_port').value = '8088';
  document.getElementById('z_https_port').value = '8443';
  document.getElementById('z_enabled').checked = true;
  document.getElementById('zoneModal').style.display = 'flex';
}

function closeZoneModal() {
  document.getElementById('zoneModal').style.display = 'none';
}

function editZone(z) {
  document.getElementById('zoneModalTitle').textContent = 'Edit Zone: ' + z.name;
  document.getElementById('z_id').value = z.id;
  document.getElementById('z_name').value = z.name;
  document.getElementById('z_slug').value = z.slug;
  const ifSelect = document.getElementById('z_interface');
  if (z.interface) {
    let found = false;
    for (let i = 0; i < ifSelect.options.length; i++) {
      if (ifSelect.options[i].value.toLowerCase() === String(z.interface).toLowerCase()) {
        ifSelect.selectedIndex = i;
        found = true;
        break;
      }
    }
    if (!found) {
      ifSelect.add(new Option(z.interface, z.interface, true, true));
      ifSelect.value = z.interface;
    }
  }
  document.getElementById('z_auth_mode').value = z.auth_mode;
  document.getElementById('z_session_timeout').value = z.session_timeout;
  document.getElementById('z_idle_timeout').value = z.idle_timeout;
  document.getElementById('z_concurrent_devices').value = z.concurrent_devices;
  document.getElementById('z_rate_limit_down_kbps').value = z.rate_limit_down_kbps;
  document.getElementById('z_rate_limit_up_kbps').value = z.rate_limit_up_kbps;
  document.getElementById('z_http_port').value = z.http_port;
  document.getElementById('z_https_port').value = z.https_port;
  document.getElementById('z_enabled').checked = parseInt(z.enabled) === 1;
  document.getElementById('zoneModal').style.display = 'flex';
}

function syncSlug() {
  const id = document.getElementById('z_id').value;
  if (!id) {
    const name = document.getElementById('z_name').value;
    document.getElementById('z_slug').value = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  }
}
</script>

<?= $this->endSection() ?>
