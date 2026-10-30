<?php
/**
 * RIP Comprehensive Panel — included by routing/index.php
 *
 * Variables expected:
 *   $ripSettings, $ripNetworks, $ripNeighbors, $ripPassiveIfaces,
 *   $interfaces, $routeMaps, $frrStatus, $isMock
 */

$rs  = $ripSettings ?? [];
$can = session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? []);

$activeRipSub = $_GET['sub'] ?? 'general';
$ripSubTabs   = ['general', 'networks', 'neighbors', 'passive', 'status'];

/**
 * Render a route-map dropdown for a redistribution field, preserving a stored
 * value that is not in the current route-map list as a selectable legacy row.
 */
$ripRmSelect = function (string $field) use ($rs, $routeMaps): string {
    $names   = array_column($routeMaps ?? [], 'name');
    $current = (string) ($rs[$field] ?? '');
    $html    = '<select name="' . esc($field, 'attr') . '" class="frr-input frr-select" style="margin-top:6px;">'
        . '<option value="">— no route-map —</option>';
    foreach (($routeMaps ?? []) as $rm) {
        $sel   = $current === $rm['name'] ? ' selected' : '';
        $html .= '<option value="' . esc($rm['name'], 'attr') . '"' . $sel . '>' . esc($rm['name']) . '</option>';
    }
    if ($current !== '' && ! in_array($current, $names, true)) {
        $html .= '<option value="' . esc($current, 'attr') . '" selected>' . esc($current) . ' (legacy)</option>';
    }
    return $html . '</select>';
};
?>

<style>
/* RIP sub-tab bar */
.rip-sub-tabs {
  display: flex;
  gap: 0;
  border-bottom: 2px solid var(--color-border);
  margin-bottom: 22px;
  overflow-x: auto;
}
.rip-sub-tab {
  display: flex; align-items: center; gap: 5px;
  padding: 7px 15px;
  font-size: 0.78rem; font-weight: 600;
  color: var(--text-muted);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: color .15s, border-color .15s;
  white-space: nowrap;
  background: none; border-top: none; border-left: none; border-right: none;
}
.rip-sub-tab:hover { color: var(--text-primary); }
.rip-sub-tab.active { color: var(--color-emerald); border-bottom-color: var(--color-emerald); }

/* RIP sub-panel content areas */
.rip-sub-panel { display: none; }
.rip-sub-panel.active { display: block; }
</style>

<!-- ── RIP Sub-Tab Bar ── -->
<div class="rip-sub-tabs">
  <button type="button" class="rip-sub-tab <?= $activeRipSub === 'general' ? 'active' : '' ?>"
          onclick="switchRipSub('general', this)">
    <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
    RIP Settings
  </button>
  <button type="button" class="rip-sub-tab <?= $activeRipSub === 'networks' ? 'active' : '' ?>"
          onclick="switchRipSub('networks', this)">
    <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
    Networks (<?= count($ripNetworks) ?>)
  </button>
  <button type="button" class="rip-sub-tab <?= $activeRipSub === 'neighbors' ? 'active' : '' ?>"
          onclick="switchRipSub('neighbors', this)">
    <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
    Neighbors (<?= count($ripNeighbors) ?>)
  </button>
  <button type="button" class="rip-sub-tab <?= $activeRipSub === 'passive' ? 'active' : '' ?>"
          onclick="switchRipSub('passive', this)">
    <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636a9 9 0 010 12.728m0-12.728L5.636 18.364m12.728-12.728L5.636 5.636m12.728 12.728L5.636 18.364"/></svg>
    Passive Interfaces (<?= count($ripPassiveIfaces) ?>)
  </button>
  <button type="button" class="rip-sub-tab <?= $activeRipSub === 'status' ? 'active' : '' ?>"
          onclick="switchRipSub('status', this)">
    <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
    Status
  </button>
</div>

<!-- ================================================================
     SUB-PANEL: RIP General Settings
     ================================================================ -->
<div class="rip-sub-panel <?= $activeRipSub === 'general' ? 'active' : '' ?>" id="rip-sub-general">
  <form method="POST" action="<?= base_url('routing/rip/settings/save') ?>">
    <?= csrf_field() ?>

    <!-- General Options -->
    <div class="frr-form">
      <div class="frr-section-hdr" style="background:rgba(16,185,129,0.12); color:var(--color-emerald);">General RIP Options</div>

      <div class="frr-row">
        <div class="frr-lbl">Enable</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="enabled" value="1" <?= !empty($rs['enabled']) ? 'checked' : '' ?>>
            Enable RIP Routing Daemon (FRRouting ripd)
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Protocol Version</div>
        <div class="frr-val">
          <select name="version" class="frr-input frr-select">
            <option value="2" <?= (int)($rs['version'] ?? 2) === 2 ? 'selected' : '' ?>>RIPv2 (multicast, subnet masks &amp; auth)</option>
            <option value="1" <?= (int)($rs['version'] ?? 2) === 1 ? 'selected' : '' ?>>RIPv1 (classful, broadcast)</option>
          </select>
          <p class="hint">RIPv2 is required for CIDR advertisements and authentication. RIPv1 is classful only.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Passive Default</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="passive_default" value="1" <?= !empty($rs['passive_default']) ? 'checked' : '' ?>>
            Suppress updates on all interfaces until explicitly enabled
          </label>
          <p class="hint">Interfaces listed under the Passive Interfaces tab remain silent even when this is off.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Administrative Distance</div>
        <div class="frr-val">
          <input type="number" name="distance" class="frr-input frr-input-sm" min="1" max="255"
                 value="<?= esc($rs['distance'] ?? '') ?>" placeholder="120">
          <p class="hint">Distance used to select RIP routes over other protocols. FRR default: 120.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Maximum Paths</div>
        <div class="frr-val">
          <input type="number" name="maximum_paths" class="frr-input frr-input-sm" min="1" max="64"
                 value="<?= esc($rs['maximum_paths'] ?? 4) ?>">
          <p class="hint">Maximum number of equal-cost paths installed for load balancing.</p>
        </div>
      </div>
    </div>

    <!-- Timers -->
    <div class="frr-form">
      <div class="frr-section-hdr" style="background:rgba(16,185,129,0.12); color:var(--color-emerald);">Timers (seconds)</div>

      <div class="frr-row">
        <div class="frr-lbl">Update<span class="sub">seconds</span></div>
        <div class="frr-val">
          <input type="number" name="timers_update" class="frr-input frr-input-sm" min="1" max="3600"
                 value="<?= esc($rs['timers_update'] ?? 30) ?>">
          <p class="hint">Interval between periodic route updates. FRR default: 30s.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Timeout<span class="sub">seconds</span></div>
        <div class="frr-val">
          <input type="number" name="timers_timeout" class="frr-input frr-input-sm" min="1" max="3600"
                 value="<?= esc($rs['timers_timeout'] ?? 180) ?>">
          <p class="hint">Time a route is marked unreachable without an update. FRR default: 180s.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Garbage<span class="sub">seconds</span></div>
        <div class="frr-val">
          <input type="number" name="timers_garbage" class="frr-input frr-input-sm" min="1" max="3600"
                 value="<?= esc($rs['timers_garbage'] ?? 120) ?>">
          <p class="hint">Time a timed-out route is retained before removal. FRR default: 120s.</p>
        </div>
      </div>
    </div>

    <!-- Redistribution -->
    <div class="frr-form">
      <div class="frr-section-hdr" style="background:rgba(16,185,129,0.12); color:var(--color-emerald);">Route Redistribution</div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute Connected</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_connected" value="1" <?= !empty($rs['redistribute_connected']) ? 'checked' : '' ?>>
            Redistribute connected interface subnets into RIP
          </label>
          <?= $ripRmSelect('redistribute_connected_rm') ?>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute Static</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_static" value="1" <?= !empty($rs['redistribute_static']) ? 'checked' : '' ?>>
            Redistribute configured static routes into RIP
          </label>
          <?= $ripRmSelect('redistribute_static_rm') ?>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute BGP</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_bgp" value="1" <?= !empty($rs['redistribute_bgp']) ? 'checked' : '' ?>>
            Redistribute BGP-learned routes into RIP
          </label>
          <?= $ripRmSelect('redistribute_bgp_rm') ?>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute OSPF</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_ospf" value="1" <?= !empty($rs['redistribute_ospf']) ? 'checked' : '' ?>>
            Redistribute OSPF-learned routes into RIP
          </label>
          <?= $ripRmSelect('redistribute_ospf_rm') ?>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribution Metric</div>
        <div class="frr-val">
          <input type="number" name="redistribute_metric" class="frr-input frr-input-sm" min="0" max="16"
                 value="<?= esc($rs['redistribute_metric'] ?? '') ?>" placeholder="optional">
          <p class="hint">Metric applied to redistributed routes (RIP hop count 0–16). Blank keeps the protocol default.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Default Information</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="default_information" value="1" <?= !empty($rs['default_information']) ? 'checked' : '' ?>>
            Originate a default route (0.0.0.0/0) into RIP
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Default Originate Route Map</div>
        <div class="frr-val">
          <select name="default_information_rm" id="ripDefaultRm" class="frr-input frr-select">
            <option value="">— none —</option>
            <?php foreach (($routeMaps ?? []) as $rm): ?>
              <option value="<?= esc($rm['name']) ?>" <?= ($rs['default_information_rm'] ?? '') === $rm['name'] ? 'selected' : '' ?>><?= esc($rm['name']) ?></option>
            <?php endforeach; ?>
            <?php if (!empty($rs['default_information_rm']) && !in_array($rs['default_information_rm'], array_column($routeMaps ?? [], 'name'), true)): ?>
              <option value="<?= esc($rs['default_information_rm']) ?>" selected><?= esc($rs['default_information_rm']) ?> (legacy)</option>
            <?php endif; ?>
          </select>
          <p class="hint">Optional route-map applied to the originated default route.</p>
        </div>
      </div>
    </div>

    <!-- Authentication -->
    <div class="frr-form">
      <div class="frr-section-hdr" style="background:rgba(16,185,129,0.12); color:var(--color-emerald);">Authentication (RIPv2)</div>

      <div class="frr-row">
        <div class="frr-lbl">Auth Mode</div>
        <div class="frr-val">
          <select name="auth_mode" id="ripAuthMode" class="frr-input frr-select" onchange="toggleRipAuth()">
            <option value="none" <?= ($rs['auth_mode'] ?? 'none') === 'none' ? 'selected' : '' ?>>None (plaintext, no auth)</option>
            <option value="text" <?= ($rs['auth_mode'] ?? 'none') === 'text' ? 'selected' : '' ?>>Simple Password (clear text)</option>
            <option value="md5"  <?= ($rs['auth_mode'] ?? 'none') === 'md5' ? 'selected' : '' ?>>MD5 Keyed Digest</option>
          </select>
          <p class="hint">Authentication must be configured identically on all RIP neighbors.</p>
        </div>
      </div>

      <div class="frr-row" id="ripAuthPwRow" style="<?= ($rs['auth_mode'] ?? 'none') === 'none' ? 'display:none;' : '' ?>">
        <div class="frr-lbl">Auth Password</div>
        <div class="frr-val">
          <input type="text" name="auth_password" class="frr-input frr-input-md frr-input-mono"
                 value="<?= esc($rs['auth_password'] ?? '') ?>" spellcheck="false" placeholder="shared secret">
          <p class="hint">Shared password used for simple-password or MD5 authentication.</p>
        </div>
      </div>
    </div>

    <?php if ($can): ?>
      <div style="display:flex; justify-content:flex-end; margin-top:16px;">
        <button type="submit" class="btn btn-primary">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          Save RIP Settings
        </button>
      </div>
    <?php endif; ?>
  </form>
</div>

<!-- ================================================================
     SUB-PANEL: RIP Networks
     ================================================================ -->
<div class="rip-sub-panel <?= $activeRipSub === 'networks' ? 'active' : '' ?>" id="rip-sub-networks">
  <div class="card" style="padding:0; overflow-x:auto;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center;">
      <div>
        <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">RIP Network Statements</span>
        <span style="font-size:0.75rem; color:var(--text-muted); margin-left:8px;">network A.B.C.D/M</span>
      </div>
      <?php if ($can): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('ripNetModal')">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Network
        </button>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead><tr><th>Network</th><th>Description</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
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
          <?php foreach ($ripNetworks as $n): ?>
            <tr>
              <td><span class="rt-chip rt-chip-green"><?= esc($n['network']) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($n['description'] ?: '—') ?></td>
              <td><?= (int)($n['enabled'] ?? 1) ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Disabled</span>' ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ($can): ?>
                  <form method="POST" action="<?= base_url('routing/rip/networks/' . esc($n['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove RIP network <?= esc(addslashes($n['network'])) ?>?')"><?= csrf_field() ?>
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

<!-- ================================================================
     SUB-PANEL: RIP Neighbors
     ================================================================ -->
<div class="rip-sub-panel <?= $activeRipSub === 'neighbors' ? 'active' : '' ?>" id="rip-sub-neighbors">
  <div class="card" style="padding:0; overflow-x:auto;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center;">
      <div>
        <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">RIP Unicast Neighbors</span>
        <span style="font-size:0.75rem; color:var(--text-muted); margin-left:8px;">neighbor A.B.C.D</span>
      </div>
      <?php if ($can): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('ripNbModal')">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Neighbor
        </button>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead><tr><th>Neighbor IP</th><th>Description</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($ripNeighbors)): ?>
          <tr><td colspan="4" style="text-align:center; padding:28px; color:var(--text-muted);">
              <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.82L3 21l1.82-3.98A7.6 7.6 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No RIP unicast neighbors configured.</p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">On broadcast segments RIP discovers peers itself through the 224.0.0.9 multicast address. Add a unicast neighbor only when that multicast cannot reach the other router — NBMA links, tunnels, or point-to-point WANs.</p>
              </div>
            </td></tr>
        <?php else: ?>
          <?php foreach ($ripNeighbors as $n): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($n['neighbor_ip']) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($n['description'] ?: '—') ?></td>
              <td><?= (int)($n['enabled'] ?? 1) ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Disabled</span>' ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ($can): ?>
                  <form method="POST" action="<?= base_url('routing/rip/neighbors/' . esc($n['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove RIP neighbor <?= esc(addslashes($n['neighbor_ip'])) ?>?')"><?= csrf_field() ?>
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

<!-- ================================================================
     SUB-PANEL: RIP Passive Interfaces
     ================================================================ -->
<div class="rip-sub-panel <?= $activeRipSub === 'passive' ? 'active' : '' ?>" id="rip-sub-passive">
  <div class="card" style="padding:0; overflow-x:auto;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center;">
      <div>
        <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">RIP Passive Interfaces</span>
        <span style="font-size:0.75rem; color:var(--text-muted); margin-left:8px;">passive-interface IFACE</span>
      </div>
      <?php if ($can): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('ripPiModal')">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Interface
        </button>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead><tr><th>Interface</th><th>Description</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($ripPassiveIfaces)): ?>
          <tr><td colspan="4" style="text-align:center; padding:28px; color:var(--text-muted);">
              <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-amber)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636a9 9 0 010 12.728M5.636 18.364a9 9 0 010-12.728M15.536 8.464a5 5 0 010 7.072M8.464 15.536a5 5 0 010-7.072"/></svg>
                <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No passive interfaces.</p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">By default RIP will advertise and receive on every enabled interface. Mark an interface passive to silence updates on a segment where no RIP peer exists — typically the WAN edge or a LAN with only client devices.</p>
              </div>
            </td></tr>
        <?php else: ?>
          <?php foreach ($ripPassiveIfaces as $p): ?>
            <tr>
              <td><span class="rt-chip"><?= esc($p['interface']) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($p['description'] ?: '—') ?></td>
              <td><?= (int)($p['enabled'] ?? 1) ? '<span class="badge badge-success">Passive</span>' : '<span class="badge badge-muted">Disabled</span>' ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ($can): ?>
                  <form method="POST" action="<?= base_url('routing/rip/passive/' . esc($p['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove passive interface <?= esc(addslashes($p['interface'])) ?>?')"><?= csrf_field() ?>
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

<!-- ================================================================
     SUB-PANEL: RIP Status
     ================================================================ -->
<div class="rip-sub-panel <?= $activeRipSub === 'status' ? 'active' : '' ?>" id="rip-sub-status">
  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:20px;">
    <div class="card" style="padding:16px;">
      <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">RIP Daemon</div>
      <div style="font-size:1.15rem; font-weight:700; color:<?= !empty($rs['enabled']) ? 'var(--color-emerald)' : 'var(--text-muted)' ?>; margin-top:4px; display:flex; align-items:center; gap:6px;">
        <?php if (!empty($rs['enabled'])): ?><span class="frr-dot" style="display:inline-block;"></span><?php endif; ?>
        <?= !empty($rs['enabled']) ? 'Running' : 'Disabled' ?>
      </div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">FRRouting ripd 9.1</div>
    </div>
    <div class="card" style="padding:16px;">
      <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Version</div>
      <div style="font-size:1.15rem; font-weight:700; color:var(--color-emerald); margin-top:4px;">RIPv<?= esc($rs['version'] ?? 2) ?></div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">Auth: <?= esc($rs['auth_mode'] ?? 'none') ?></div>
    </div>
    <div class="card" style="padding:16px;">
      <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Networks</div>
      <div style="font-size:1.15rem; font-weight:700; color:var(--color-cyan); margin-top:4px;"><?= count($ripNetworks) ?> Configured</div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;"><?= count($ripNeighbors) ?> unicast neighbors</div>
    </div>
    <div class="card" style="padding:16px;">
      <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Timers</div>
      <div style="font-size:1.15rem; font-weight:700; color:var(--text-primary); margin-top:4px;">
        <?= esc($rs['timers_update'] ?? 30) ?> / <?= esc($rs['timers_timeout'] ?? 180) ?> / <?= esc($rs['timers_garbage'] ?? 120) ?>
      </div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">Update / Timeout / Garbage (s)</div>
    </div>
  </div>

  <div class="card" style="padding:0; overflow-x:auto;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border); font-weight:700; font-size:0.875rem;">
      Live RIP Routes (show ip rip)
    </div>
    <table class="rt-table">
      <thead><tr><th>Network</th><th>Via</th><th>Interface</th><th>Metric</th><th>Tag</th><th>Uptime</th></tr></thead>
      <tbody>
        <?php if (empty($ripNetworks)): ?>
          <tr><td colspan="6" style="text-align:center; padding:28px; color:var(--text-muted);">No RIP routes learned.</td></tr>
        <?php else: ?>
          <?php foreach ($ripNetworks as $n): ?>
            <tr>
              <td><span class="rt-chip rt-chip-green"><?= esc($n['network']) ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem;"><?= esc($ripNeighbors[0]['neighbor_ip'] ?? 'connected') ?></td>
              <td><span class="rt-chip"><?= esc($ripPassiveIfaces[0]['interface'] ?? 'eth1') ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem;"><?= esc($rs['redistribute_metric'] ?? 1) ?></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem;">0</td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--color-emerald);">00:12:41</td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ================================================================
     MODALS
     ================================================================ -->

<!-- Add Network -->
<div class="rt-modal-backdrop" id="ripNetModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">Add RIP Network</span>
      <button type="button" onclick="closeModal('ripNetModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('routing/rip/networks/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-field"><label>Network (CIDR) *</label><input type="text" name="network" required placeholder="192.168.1.0/24" class="wg-input-mono" spellcheck="false"></div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" placeholder="Corporate LAN subnet"></div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('ripNetModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Add Network</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Neighbor -->
<div class="rt-modal-backdrop" id="ripNbModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">Add RIP Neighbor</span>
      <button type="button" onclick="closeModal('ripNbModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('routing/rip/neighbors/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-field"><label>Neighbor IP *</label><input type="text" name="neighbor_ip" required placeholder="10.100.0.2" class="wg-input-mono" spellcheck="false"></div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" placeholder="Core switch unicast peer"></div>
        <p class="hint" style="font-size:0.72rem; color:var(--text-muted);">Adds an explicit unicast peer in addition to RIPv2 multicast discovery.</p>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('ripNbModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Add Neighbor</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Passive Interface -->
<div class="rt-modal-backdrop" id="ripPiModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">Add Passive Interface</span>
      <button type="button" onclick="closeModal('ripPiModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('routing/rip/passive/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-field">
          <label>Interface *</label>
          <select name="interface" required class="wg-input-mono">
            <?php foreach (($interfaces ?? []) as $if): ?>
              <option value="<?= esc($if['physical_device']) ?>"><?= esc($if['name']) ?> (<?= esc($if['physical_device']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" placeholder="LAN — listen only"></div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('ripPiModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Add Interface</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── RIP sub-tab switching ──
function switchRipSub(name, btn) {
  document.querySelectorAll('.rip-sub-panel').forEach(function(p) { p.classList.remove('active'); });
  document.querySelectorAll('.rip-sub-tab').forEach(function(t) { t.classList.remove('active'); });
  var panel = document.getElementById('rip-sub-' + name);
  if (panel) panel.classList.add('active');
  if (btn) btn.classList.add('active');
  var url = new URL(window.location);
  url.searchParams.set('tab', 'rip');
  url.searchParams.set('sub', name);
  history.replaceState(null, '', url);
}

// ── Auto-activate RIP sub-tab from ?sub= param ──
(function() {
  var sub = new URLSearchParams(window.location.search).get('sub');
  if (sub && document.getElementById('rip-sub-' + sub)) {
    var btn = document.querySelector('.rip-sub-tab[onclick*="\'' + sub + '\'"]');
    var p = document.getElementById('rip-sub-' + sub);
    if (btn && p) {
      document.querySelectorAll('.rip-sub-panel').forEach(function(el) { el.classList.remove('active'); });
      document.querySelectorAll('.rip-sub-tab').forEach(function(el) { el.classList.remove('active'); });
      p.classList.add('active');
      btn.classList.add('active');
    }
  }
})();

// ── Toggle the auth password field ──
function toggleRipAuth() {
  var mode = document.getElementById('ripAuthMode').value;
  document.getElementById('ripAuthPwRow').style.display = (mode === 'none') ? 'none' : '';
}
</script>
