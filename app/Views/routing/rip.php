<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
/* Page-specific styles */
.frr-banner {
  display: flex;
  align-items: center;
  gap: 14px;
  background: linear-gradient(135deg, rgba(6,182,212,0.08), rgba(139,92,246,0.08));
  border: 1px solid rgba(6,182,212,0.25);
  border-radius: var(--radius-lg);
  padding: 12px 20px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.frr-badge {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.78rem;
  color: var(--text-secondary);
}
.frr-badge strong { color: var(--text-primary); }
.frr-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: var(--color-emerald);
  box-shadow: 0 0 6px var(--color-emerald);
}

.frr-form { border:1px solid var(--color-border); border-radius:var(--radius-md); overflow:hidden; margin-bottom:20px; background:var(--color-surface-1); }
.frr-section-hdr {
  background: rgba(16,185,129,0.1);
  border-bottom: 1px solid var(--color-border);
  padding: 8px 18px;
  font-size: 0.72rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--color-emerald);
}
.frr-row {
  display: grid;
  grid-template-columns: 200px 1fr;
  border-bottom: 1px solid var(--color-border);
  min-height: 46px;
}
.frr-row:last-child { border-bottom: none; }
.frr-lbl {
  padding: 11px 18px;
  font-size: 0.82rem; font-weight: 600;
  color: var(--text-secondary);
  border-right: 1px solid var(--color-border);
  display: flex; align-items: flex-start; flex-direction: column;
  justify-content: center; gap: 2px;
  background: rgba(255,255,255,0.015);
}
.frr-lbl .sub { font-size: 0.68rem; font-weight: 400; color: var(--text-muted); }
.frr-val {
  padding: 9px 18px;
  display: flex; flex-direction: column; gap: 4px;
}
.frr-val .hint { font-size: 0.71rem; color: var(--text-muted); line-height: 1.4; }
.frr-input {
  background: var(--color-surface-3);
  border: 1px solid var(--color-border);
  color: var(--text-primary);
  padding: 6px 9px;
  border-radius: var(--radius-sm);
  font-size: 0.85rem;
  max-width: 400px;
  width: 100%;
}
.frr-input:focus { outline: none; border-color: var(--color-emerald); }
.frr-input-sm  { max-width: 140px; }
.frr-input-md  { max-width: 260px; }
.frr-input-mono { font-family: var(--font-mono); }
.frr-select { max-width: 320px; }
.frr-chk {
  display: flex; align-items: center; gap: 8px;
  font-size: 0.83rem; color: var(--text-secondary); margin-top: 2px;
}
.frr-chk input { accent-color: var(--color-emerald); width: 16px; height: 16px; }
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <span>Network</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>Routing Engine</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>RIP</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:var(--tone-emerald-bg); color:var(--tone-emerald-fg); border:1px solid var(--tone-emerald-border);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/>
        </svg>
      </div>
      <div>
        <h1 class="page-title">RIP (Routing Information Protocol)</h1>
        <p class="page-subtitle">Configure RIPv2 networks, unicast neighbors, passive interfaces, and route redistribution</p>
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
  <div class="frr-badge">
    <span class="frr-dot"></span>
    <strong>FRRouting</strong>
  </div>
  <div class="frr-badge">Version: <strong><?= esc($frrStatus['version'] ?? '') ?></strong></div>
  <div class="frr-badge">Uptime: <strong><?= esc($frrStatus['uptime'] ?? '') ?></strong></div>
  <div class="frr-badge">Local AS: <strong class="rt-chip rt-chip-blue"><?= esc($frrStatus['bgp_as'] ?? '') ?></strong></div>
  <div class="frr-badge">Router-ID: <strong class="rt-chip rt-chip-cyan"><?= esc($frrStatus['router_id'] ?? '') ?></strong></div>
  <?php if (!empty($isMock)): ?>
    <span class="badge badge-muted" style="margin-left:auto;">simulation mode</span>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════
     RIP SETTINGS
     ════════════════════════════════════════════════════════════════ -->
<div style="margin-bottom:24px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0;">RIP Settings — daemon-wide behaviour for ripd</h3>
    <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('ripSettingsModal')">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Edit Settings
      </button>
    <?php endif; ?>
  </div>

  <div class="frr-form">
    <div class="frr-section-hdr">RIPv2 Daemon Settings</div>
    <form method="POST" action="<?= base_url('routing/rip/settings/save') ?>">
      <?= csrf_field() ?>
      <div class="frr-row">
        <div class="frr-lbl">Enable RIP</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="enabled" value="1" <?= !empty($ripSettings['enabled']) ? 'checked' : '' ?>>
            Enable RIP routing daemon (ripd)
          </label>
        </div>
      </div>
      <div class="frr-row">
        <div class="frr-lbl">Version</div>
        <div class="frr-val">
          <select name="version" class="frr-input frr-select">
            <option value="2" <?= ($ripSettings['version'] ?? 2) == 2 ? 'selected' : '' ?>>RIPv2 (default)</option>
            <option value="1" <?= ($ripSettings['version'] ?? 2) == 1 ? 'selected' : '' ?>>RIPv1 (legacy)</option>
          </select>
          <p class="hint">RIPv2 supports CIDR, authentication, and multicast updates. RIPv1 is classful and deprecated.</p>
        </div>
      </div>
      <div class="frr-row">
        <div class="frr-lbl">Router ID</div>
        <div class="frr-val">
          <input type="text" name="router_id" class="frr-input frr-input-mono frr-input-md"
                 value="<?= esc($ripSettings['router_id'] ?? '') ?>" spellcheck="false" placeholder="auto">
          <p class="hint">Override router ID. Leave blank for auto-detection.</p>
        </div>
      </div>
      <div class="frr-row">
        <div class="frr-lbl">Redistribute Connected</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_connected" value="1" <?= !empty($ripSettings['redistribute_connected']) ? 'checked' : '' ?>>
            Redistribute connected routes into RIP
          </label>
        </div>
      </div>
      <div class="frr-row">
        <div class="frr-lbl">Redistribute Static</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_static" value="1" <?= !empty($ripSettings['redistribute_static']) ? 'checked' : '' ?>>
            Redistribute static routes into RIP
          </label>
        </div>
      </div>
      <div class="frr-row">
        <div class="frr-lbl">Redistribute BGP</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_bgp" value="1" <?= !empty($ripSettings['redistribute_bgp']) ? 'checked' : '' ?>>
            Redistribute BGP routes into RIP
          </label>
        </div>
      </div>
      <div class="frr-row">
        <div class="frr-lbl">Redistribute OSPF</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_ospf" value="1" <?= !empty($ripSettings['redistribute_ospf']) ? 'checked' : '' ?>>
            Redistribute OSPF routes into RIP
          </label>
        </div>
      </div>
      <div class="frr-row">
        <div class="frr-lbl">Default Metric</div>
        <div class="frr-val">
          <input type="number" name="default_metric" class="frr-input frr-input-sm"
                 value="<?= esc($ripSettings['default_metric'] ?? 1) ?>" min="1" max="15">
          <p class="hint">Metric applied to redistributed routes (1-15, 15 = unreachable).</p>
        </div>
      </div>
      <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
        <div style="display:flex; justify-content:flex-end; margin-top:16px;">
          <button type="submit" class="btn btn-primary">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            Save RIP Settings
          </button>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     RIP NETWORKS
     ════════════════════════════════════════════════════════════════ -->
<div style="margin-bottom:24px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0;">RIP Network Statements — subnets RIP advertises and accepts updates on</h3>
    <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('ripNetworkModal')">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Network
      </button>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:0; overflow-x:auto;">
    <table class="rt-table">
      <thead><tr>
        <th title="Subnet that RIP advertises and accepts updates for">Network</th>
        <th title="RIPv1 is classful and deprecated; RIPv2 supports CIDR and authentication">Version</th>
        <th>Description</th>
        <th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($ripNetworks)): ?>
          <tr><td colspan="4" style="text-align:center; padding:28px; color:var(--text-muted);">
              <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-emerald)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
                <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No RIP network statements configured.</p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">RIP only advertises interfaces that fall inside a network statement — add each subnet you want ripd to send and receive updates on. Without one, the daemon runs but exchanges no routes.</p>
              </div>
            </td></tr>
        <?php else: ?>
          <?php foreach ($ripNetworks as $rn): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($rn['network']) ?></span></td>
              <td><span class="badge badge-info">RIPv<?= esc($rn['version'] ?? 2) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($rn['description'] ?: '—') ?></td>
              <td style="text-align:right;">
                <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
                  <form method="POST" action="<?= base_url('routing/rip/networks/' . esc($rn['id']) . '/delete') ?>" style="display:inline;"
                        onsubmit="return confirm('Remove RIP network <?= esc(addslashes($rn['network'])) ?>?')"><?= csrf_field() ?>
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

<!-- ════════════════════════════════════════════════════════════════
     RIP NEIGHBORS (UNICAST)
     ════════════════════════════════════════════════════════════════ -->
<div style="margin-bottom:24px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0;">RIP Unicast Neighbors — manual peers where multicast cannot reach</h3>
    <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('ripNeighborModal')">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Neighbor
      </button>
    <?php endif; ?>
  </div>

  <p style="font-size:0.78rem; color:var(--text-muted); margin-bottom:12px;">
    Needed for non-broadcast multi-access (NBMA) segments, tunnels, and point-to-point WANs, where RIP cannot discover peers through the 224.0.0.9 multicast group.
  </p>

  <div class="card" style="padding:0; overflow-x:auto;">
    <table class="rt-table">
      <thead><tr>
        <th title="Address RIP sends unicast updates to">Neighbor IP</th>
        <th title="Interface used to reach the neighbor; auto lets the routing table decide">Interface</th>
        <th>Version</th>
        <th>Description</th>
        <th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($ripNeighbors)): ?>
          <tr><td colspan="5" style="text-align:center; padding:28px; color:var(--text-muted);">
              <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.82L3 21l1.82-3.98A7.6 7.6 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No RIP unicast neighbors configured.</p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">On broadcast segments RIP finds peers by itself through the 224.0.0.9 multicast address. Add a unicast neighbor only when that multicast cannot reach the other router — NBMA links, tunnels, or point-to-point WANs.</p>
              </div>
            </td></tr>
        <?php else: ?>
          <?php foreach ($ripNeighbors as $rn): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($rn['neighbor_ip']) ?></span></td>
              <td><span class="rt-chip"><?= esc($rn['interface'] ?? 'auto') ?></span></td>
              <td><span class="badge badge-info">v<?= esc($rn['version'] ?? 2) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($rn['description'] ?: '—') ?></td>
              <td style="text-align:right;">
                <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
                  <form method="POST" action="<?= base_url('routing/rip/neighbors/' . esc($rn['id']) . '/delete') ?>" style="display:inline;"
                        onsubmit="return confirm('Remove RIP neighbor <?= esc(addslashes($rn['neighbor_ip'])) ?>?')"><?= csrf_field() ?>
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

<!-- ════════════════════════════════════════════════════════════════
     RIP PASSIVE INTERFACES
     ════════════════════════════════════════════════════════════════ -->
<?php if (!empty($ripPassiveInterfaces)): ?>
<div style="margin-bottom:24px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0;">RIP Passive Interfaces</h3>
  </div>

  <p style="font-size:0.78rem; color:var(--text-muted); margin-bottom:12px;">
    Passive interfaces send RIP updates but do not receive them. Useful for edge interfaces where you want to advertise routes but not learn from the segment.
  </p>

  <div class="card" style="padding:0; overflow-x:auto;">
    <table class="rt-table">
      <thead><tr>
        <th>Interface</th>
        <th>Description</th>
        <th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php foreach ($ripPassiveInterfaces as $pi): ?>
          <tr>
            <td><span class="rt-chip rt-chip-cyan"><?= esc($pi['interface']) ?></span></td>
            <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($pi['description'] ?: '—') ?></td>
            <td style="text-align:right;">
              <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
                <form method="POST" action="<?= base_url('routing/rip/passive/' . esc($pi['id']) . '/delete') ?>" style="display:inline;"
                      onsubmit="return confirm('Remove passive interface <?= esc(addslashes($pi['interface'])) ?>?')"><?= csrf_field() ?>
                  <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── RIP Settings Modal ── -->
<div class="rt-modal-backdrop" id="ripSettingsModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">RIP Settings</span>
      <button type="button" onclick="closeModal('ripSettingsModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('routing/rip/settings/save') ?>">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="max-height:60vh; overflow-y:auto;">
        <div class="frr-form">
          <div class="frr-section-hdr">RIPv2 Daemon Settings</div>
          <div class="frr-row">
            <div class="frr-lbl">Enable RIP</div>
            <div class="frr-val">
              <label class="frr-chk">
                <input type="checkbox" name="enabled" value="1" <?= !empty($ripSettings['enabled']) ? 'checked' : '' ?>>
                Enable RIP routing daemon (ripd)
              </label>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Version</div>
            <div class="frr-val">
              <select name="version" class="frr-input frr-select">
                <option value="2" <?= ($ripSettings['version'] ?? 2) == 2 ? 'selected' : '' ?>>RIPv2</option>
                <option value="1" <?= ($ripSettings['version'] ?? 2) == 1 ? 'selected' : '' ?>>RIPv1</option>
              </select>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Router ID</div>
            <div class="frr-val">
              <input type="text" name="router_id" class="frr-input frr-input-mono frr-input-md"
                     value="<?= esc($ripSettings['router_id'] ?? '') ?>" spellcheck="false" placeholder="auto">
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Redistribute Connected</div>
            <div class="frr-val">
              <label class="frr-chk">
                <input type="checkbox" name="redistribute_connected" value="1" <?= !empty($ripSettings['redistribute_connected']) ? 'checked' : '' ?>>
                Connected routes
              </label>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Redistribute Static</div>
            <div class="frr-val">
              <label class="frr-chk">
                <input type="checkbox" name="redistribute_static" value="1" <?= !empty($ripSettings['redistribute_static']) ? 'checked' : '' ?>>
                Static routes
              </label>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Redistribute BGP</div>
            <div class="frr-val">
              <label class="frr-chk">
                <input type="checkbox" name="redistribute_bgp" value="1" <?= !empty($ripSettings['redistribute_bgp']) ? 'checked' : '' ?>>
                BGP routes
              </label>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Redistribute OSPF</div>
            <div class="frr-val">
              <label class="frr-chk">
                <input type="checkbox" name="redistribute_ospf" value="1" <?= !empty($ripSettings['redistribute_ospf']) ? 'checked' : '' ?>>
                OSPF routes
              </label>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Default Metric</div>
            <div class="frr-val">
              <input type="number" name="default_metric" class="frr-input frr-input-sm"
                     value="<?= esc($ripSettings['default_metric'] ?? 1) ?>" min="1" max="15">
            </div>
          </div>
        </div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('ripSettingsModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save Settings</button>
      </div>
    </form>
  </div>
</div>

<!-- ── RIP Network Modal ── -->
<div class="rt-modal-backdrop" id="ripNetworkModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">Add RIP Network</span>
      <button type="button" onclick="closeModal('ripNetworkModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('routing/rip/networks/store') ?>">
      <?= csrf_field() ?>
      <div class="rt-modal-body">
        <div class="rt-grid-2">
          <div class="rt-field">
            <label>Network *</label>
            <input type="text" name="network" required placeholder="192.168.1.0/24" class="wg-input-mono" spellcheck="false">
          </div>
          <div class="rt-field">
            <label>Version</label>
            <select name="version">
              <option value="2" selected>RIPv2</option>
              <option value="1">RIPv1</option>
            </select>
          </div>
        </div>
        <div class="rt-field">
          <label>Description</label>
          <input type="text" name="description" placeholder="LAN subnet">
        </div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('ripNetworkModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Add Network</button>
      </div>
    </form>
  </div>
</div>

<!-- ── RIP Neighbor Modal ── -->
<div class="rt-modal-backdrop" id="ripNeighborModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">Add RIP Neighbor</span>
      <button type="button" onclick="closeModal('ripNeighborModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('routing/rip/neighbors/store') ?>">
      <?= csrf_field() ?>
      <div class="rt-modal-body">
        <div class="rt-grid-2">
          <div class="rt-field">
            <label>Neighbor IP *</label>
            <input type="text" name="neighbor_ip" required placeholder="10.0.0.1" class="wg-input-mono" spellcheck="false">
          </div>
          <div class="rt-field">
            <label>Interface</label>
            <input type="text" name="interface" placeholder="eth0 (optional)" class="wg-input-mono" spellcheck="false">
          </div>
        </div>
        <div class="rt-field">
          <label>Description</label>
          <input type="text" name="description" placeholder="NBMA peer">
        </div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('ripNeighborModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Add Neighbor</button>
      </div>
    </form>
  </div>
</div>

<script>
// Auto-open modals based on URL params if needed
(function() {
  var tab = new URLSearchParams(window.location.search).get('tab');
  if (tab === 'rip') {
    // Highlight the RIP tab in the main routing page
    console.log('RIP tab active');
  }
})();
</script>

<?= $this->endSection() ?>
