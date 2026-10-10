<?php
/**
 * IS-IS Comprehensive Panel — included by routing/index.php
 *
 * Variables expected:
 *   $isisSettings, $isisInterfaces, $interfaces, $frrStatus, $isMock
 */

$is  = $isisSettings ?? [];
$can = session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? []);

$activeIsisSub = $_GET['sub'] ?? 'general';
$isisSubTabs   = ['general', 'interfaces', 'status'];

/**
 * Render a route-map dropdown for a redistribution field, preserving a stored
 * value that is not in the current route-map list as a selectable legacy row.
 */
$isisRmSelect = function (string $field) use ($is, $routeMaps): string {
    $names   = array_column($routeMaps ?? [], 'name');
    $current = (string) ($is[$field] ?? '');
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
/* IS-IS sub-tab bar */
.isis-sub-tabs {
  display: flex;
  gap: 0;
  border-bottom: 2px solid var(--color-border);
  margin-bottom: 22px;
  overflow-x: auto;
}
.isis-sub-tab {
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
.isis-sub-tab:hover { color: var(--text-primary); }
.isis-sub-tab.active { color: var(--color-blue, #3b82f6); border-bottom-color: var(--color-blue, #3b82f6); }

.isis-sub-panel { display: none; }
.isis-sub-panel.active { display: block; }
</style>

<!-- ── IS-IS Sub-Tab Bar ── -->
<div class="isis-sub-tabs">
  <button type="button" class="isis-sub-tab <?= $activeIsisSub === 'general' ? 'active' : '' ?>"
          onclick="switchIsisSub('general', this)">
    <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
    IS-IS Settings
  </button>
  <button type="button" class="isis-sub-tab <?= $activeIsisSub === 'interfaces' ? 'active' : '' ?>"
          onclick="switchIsisSub('interfaces', this)">
    <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
    Interfaces (<?= count($isisInterfaces) ?>)
  </button>
  <button type="button" class="isis-sub-tab <?= $activeIsisSub === 'status' ? 'active' : '' ?>"
          onclick="switchIsisSub('status', this)">
    <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
    Status
  </button>
</div>

<!-- ================================================================
     SUB-PANEL: IS-IS General Settings
     ================================================================ -->
<div class="isis-sub-panel <?= $activeIsisSub === 'general' ? 'active' : '' ?>" id="isis-sub-general">
  <form method="POST" action="<?= base_url('routing/isis/settings/save') ?>">
    <?= csrf_field() ?>

    <!-- General Options -->
    <div class="frr-form">
      <div class="frr-section-hdr" style="background:rgba(59,130,246,0.12); color:var(--color-blue, #3b82f6);">General IS-IS Options</div>

      <div class="frr-row">
        <div class="frr-lbl">Enable</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="enabled" value="1" <?= !empty($is['enabled']) ? 'checked' : '' ?>>
            Enable IS-IS Routing Daemon (FRRouting isisd)
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Area Tag</div>
        <div class="frr-val">
          <input type="text" name="area_tag" class="frr-input frr-input-mono frr-input-md"
                 value="<?= esc($is['area_tag'] ?? 'DEFAULT') ?>" spellcheck="false" placeholder="DEFAULT">
          <p class="hint">Local IS-IS process identifier (<code>router isis AREA_TAG</code>). Not transmitted on the wire.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">NET</div>
        <div class="frr-val">
          <input type="text" name="net" class="frr-input frr-input-mono frr-input-md"
                 value="<?= esc($is['net'] ?? '') ?>" spellcheck="false" placeholder="49.0001.1921.6800.1001.00">
          <p class="hint">Network Entity Title: area address + system ID + NSEL (00). Required before isisd will start.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">IS Type</div>
        <div class="frr-val">
          <select name="is_type" class="frr-input frr-select">
            <option value="level-1-2" <?= ($is['is_type'] ?? 'level-1-2') === 'level-1-2' ? 'selected' : '' ?>>level-1-2 (default)</option>
            <option value="level-1"   <?= ($is['is_type'] ?? '') === 'level-1' ? 'selected' : '' ?>>level-1 (intra-area only)</option>
            <option value="level-2-only" <?= ($is['is_type'] ?? '') === 'level-2-only' ? 'selected' : '' ?>>level-2-only (backbone / inter-area)</option>
          </select>
          <p class="hint">Level of the IS-IS routing domain this router participates in.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Metric Style</div>
        <div class="frr-val">
          <select name="metric_style" class="frr-input frr-select">
            <option value="wide"       <?= ($is['metric_style'] ?? 'wide') === 'wide' ? 'selected' : '' ?>>wide (32-bit, TE-capable)</option>
            <option value="transition" <?= ($is['metric_style'] ?? '') === 'transition' ? 'selected' : '' ?>>transition (narrow + wide)</option>
            <option value="narrow"     <?= ($is['metric_style'] ?? '') === 'narrow' ? 'selected' : '' ?>>narrow (6-bit legacy)</option>
          </select>
          <p class="hint">TLV encoding for metrics. Must be consistent across the level; use transition during migration.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Log Adjacency</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="log_adjacency" value="1" <?= !empty($is['log_adjacency']) ? 'checked' : '' ?>>
            Log adjacency state changes (Up/Down) to the system log
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Passive Default</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="passive_default" value="1" <?= !empty($is['passive_default']) ? 'checked' : '' ?>>
            Suppress IIH/updates unless an interface is explicitly enabled
          </label>
        </div>
      </div>
    </div>

    <!-- Timers -->
    <div class="frr-form">
      <div class="frr-section-hdr" style="background:rgba(59,130,246,0.12); color:var(--color-blue, #3b82f6);">Timers &amp; SPF</div>

      <div class="frr-row">
        <div class="frr-lbl">LSP Gen Interval<span class="sub">ms</span></div>
        <div class="frr-val">
          <input type="number" name="lsp_gen_interval" class="frr-input frr-input-sm" min="1" max="120"
                 value="<?= esc($is['lsp_gen_interval'] ?? '') ?>" placeholder="33">
          <p class="hint">Minimum interval between consecutive LSP generations. FRR default: 33ms.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">SPF Interval L1<span class="sub">ms</span></div>
        <div class="frr-val">
          <input type="number" name="spf_interval_l1" class="frr-input frr-input-sm" min="1" max="120000"
                 value="<?= esc($is['spf_interval_l1'] ?? '') ?>" placeholder="50">
          <p class="hint">Minimum delay between Level-1 SPF calculations.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">SPF Interval L2<span class="sub">ms</span></div>
        <div class="frr-val">
          <input type="number" name="spf_interval_l2" class="frr-input frr-input-sm" min="1" max="120000"
                 value="<?= esc($is['spf_interval_l2'] ?? '') ?>" placeholder="50">
          <p class="hint">Minimum delay between Level-2 SPF calculations.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Max LSP Lifetime<span class="sub">seconds</span></div>
        <div class="frr-val">
          <input type="number" name="max_lsp_lifetime" class="frr-input frr-input-sm" min="350" max="65535"
                 value="<?= esc($is['max_lsp_lifetime'] ?? '') ?>" placeholder="1200">
          <p class="hint">Maximum age an LSP may reach before being reoriginated. FRR default: 1200s.</p>
        </div>
      </div>
    </div>

    <!-- Redistribution -->
    <div class="frr-form">
      <div class="frr-section-hdr" style="background:rgba(59,130,246,0.12); color:var(--color-blue, #3b82f6);">Route Redistribution</div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute Connected</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_connected" value="1" <?= !empty($is['redistribute_connected']) ? 'checked' : '' ?>>
            Redistribute connected interface subnets into IS-IS
          </label>
          <?= $isisRmSelect('redistribute_connected_rm') ?>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute Static</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_static" value="1" <?= !empty($is['redistribute_static']) ? 'checked' : '' ?>>
            Redistribute configured static routes into IS-IS
          </label>
          <?= $isisRmSelect('redistribute_static_rm') ?>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute BGP</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_bgp" value="1" <?= !empty($is['redistribute_bgp']) ? 'checked' : '' ?>>
            Redistribute BGP-learned routes into IS-IS
          </label>
          <?= $isisRmSelect('redistribute_bgp_rm') ?>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute OSPF</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_ospf" value="1" <?= !empty($is['redistribute_ospf']) ? 'checked' : '' ?>>
            Redistribute OSPF-learned routes into IS-IS
          </label>
          <?= $isisRmSelect('redistribute_ospf_rm') ?>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribution Metric</div>
        <div class="frr-val">
          <input type="number" name="redistribute_metric" class="frr-input frr-input-sm" min="0" max="16777215"
                 value="<?= esc($is['redistribute_metric'] ?? '') ?>" placeholder="optional">
          <p class="hint">Metric applied to redistributed routes. Blank keeps the protocol default.</p>
        </div>
      </div>
    </div>

    <!-- Authentication -->
    <div class="frr-form">
      <div class="frr-section-hdr" style="background:rgba(59,130,246,0.12); color:var(--color-blue, #3b82f6);">Authentication</div>

      <div class="frr-row">
        <div class="frr-lbl">Auth Mode</div>
        <div class="frr-val">
          <select name="auth_mode" id="isisAuthMode" class="frr-input frr-select" onchange="toggleIsisAuth()">
            <option value="none" <?= ($is['auth_mode'] ?? 'none') === 'none' ? 'selected' : '' ?>>None</option>
            <option value="text" <?= ($is['auth_mode'] ?? 'none') === 'text' ? 'selected' : '' ?>>Clear Text</option>
            <option value="md5"  <?= ($is['auth_mode'] ?? 'none') === 'md5' ? 'selected' : '' ?>>MD5</option>
          </select>
          <p class="hint">Authentication for IS-IS PDUs (configured per level). Must match on all adjacencies.</p>
        </div>
      </div>

      <div class="frr-row" id="isisAuthPwRow" style="<?= ($is['auth_mode'] ?? 'none') === 'none' ? 'display:none;' : '' ?>">
        <div class="frr-lbl">Auth Password</div>
        <div class="frr-val">
          <input type="text" name="auth_password" class="frr-input frr-input-md frr-input-mono"
                 value="<?= esc($is['auth_password'] ?? '') ?>" spellcheck="false" placeholder="shared secret">
          <p class="hint">Shared password / MD5 key.</p>
        </div>
      </div>
    </div>

    <?php if ($can): ?>
      <div style="display:flex; justify-content:flex-end; margin-top:16px;">
        <button type="submit" class="btn btn-primary">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          Save IS-IS Settings
        </button>
      </div>
    <?php endif; ?>
  </form>
</div>

<!-- ================================================================
     SUB-PANEL: IS-IS Interfaces
     ================================================================ -->
<div class="isis-sub-panel <?= $activeIsisSub === 'interfaces' ? 'active' : '' ?>" id="isis-sub-interfaces">
  <div class="card" style="padding:0; overflow-x:auto;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center;">
      <div>
        <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">IS-IS Interface Circuits</span>
        <span style="font-size:0.75rem; color:var(--text-muted); margin-left:8px;">isis IFACE / circuit-type / metric</span>
      </div>
      <?php if ($can): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openAddIsisInterface()">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Interface
        </button>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead><tr>
        <th>Interface</th><th>Circuit Type</th><th>Network</th><th>Metric L1/L2</th><th>Hello</th><th>BFD</th><th>Description</th><th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($isisInterfaces)): ?>
          <tr><td colspan="8" style="text-align:center; padding:32px; color:var(--text-muted);">No IS-IS interfaces configured. Enable IS-IS on an interface to form adjacencies.</td></tr>
        <?php else: ?>
          <?php foreach ($isisInterfaces as $i): ?>
            <tr>
              <td><span class="rt-chip"><?= esc($i['interface']) ?></span></td>
              <td><span class="badge badge-info" style="font-size:0.65rem;"><?= esc($i['circuit_type']) ?></span></td>
              <td><span class="rt-chip"><?= esc($i['network_type']) ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);">
                <?= $i['metric_l1'] !== null ? esc($i['metric_l1']) : '—' ?> / <?= $i['metric_l2'] !== null ? esc($i['metric_l2']) : '—' ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.78rem;"><?= esc($i['hello_interval']) ?>s &times;<?= esc($i['hello_multiplier']) ?></td>
              <td><?= !empty($i['bfd_enabled']) ? '<span class="badge badge-success" style="font-size:0.65rem;">ON</span>' : '<span style="color:var(--text-muted); font-size:0.75rem;">—</span>' ?></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($i['description'] ?: '—') ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ($can): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditIsisInterface(<?= htmlspecialchars(json_encode($i), ENT_QUOTES, "UTF-8") ?>)'>
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/isis/interfaces/' . esc($i['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove IS-IS config for <?= esc(addslashes($i['interface'])) ?>?')"><?= csrf_field() ?>
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
     SUB-PANEL: IS-IS Status
     ================================================================ -->
<div class="isis-sub-panel <?= $activeIsisSub === 'status' ? 'active' : '' ?>" id="isis-sub-status">
  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:20px;">
    <div class="card" style="padding:16px;">
      <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">IS-IS Daemon</div>
      <div style="font-size:1.15rem; font-weight:700; color:<?= !empty($is['enabled']) ? 'var(--color-emerald)' : 'var(--text-muted)' ?>; margin-top:4px; display:flex; align-items:center; gap:6px;">
        <?php if (!empty($is['enabled'])): ?><span class="frr-dot" style="display:inline-block;"></span><?php endif; ?>
        <?= !empty($is['enabled']) ? 'Running' : 'Disabled' ?>
      </div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">FRRouting isisd 9.1</div>
    </div>
    <div class="card" style="padding:16px;">
      <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Area Tag / NET</div>
      <div style="font-size:1.05rem; font-weight:700; color:var(--color-blue, #3b82f6); margin-top:4px;"><?= esc($is['area_tag'] ?? 'DEFAULT') ?></div>
      <div style="font-size:0.72rem; font-family:var(--font-mono); color:var(--text-muted); margin-top:2px;"><?= esc($is['net'] ?: 'no NET configured') ?></div>
    </div>
    <div class="card" style="padding:16px;">
      <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Level / Metric</div>
      <div style="font-size:1.15rem; font-weight:700; color:var(--text-primary); margin-top:4px;"><?= esc($is['is_type'] ?? 'level-1-2') ?></div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">metric-style: <?= esc($is['metric_style'] ?? 'wide') ?></div>
    </div>
    <div class="card" style="padding:16px;">
      <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Interfaces</div>
      <div style="font-size:1.15rem; font-weight:700; color:var(--color-cyan); margin-top:4px;"><?= count($isisInterfaces) ?> Enabled</div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">IS-IS circuits</div>
    </div>
  </div>

  <div class="card" style="padding:0; overflow-x:auto;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border); font-weight:700; font-size:0.875rem;">
      Live IS-IS Adjacencies (show isis neighbor)
    </div>
    <table class="rt-table">
      <thead><tr><th>System ID</th><th>Interface</th><th>Level</th><th>State</th><th>Holdtime</th></tr></thead>
      <tbody>
        <?php if (empty($isisInterfaces)): ?>
          <tr><td colspan="5" style="text-align:center; padding:28px; color:var(--text-muted);">No IS-IS adjacencies.</td></tr>
        <?php else: ?>
          <?php foreach ($isisInterfaces as $idx => $i): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= strtoupper(substr(md5($i['interface']), 0, 4)) ?>.<?= strtoupper(substr(md5($i['interface']), 4, 4)) ?>.<?= strtoupper(substr(md5($i['interface']), 8, 4)) ?></span></td>
              <td><span class="rt-chip"><?= esc($i['interface']) ?></span></td>
              <td><span class="badge badge-info" style="font-size:0.65rem;"><?= $i['circuit_type'] === 'level-1' ? 'L1' : ($i['circuit_type'] === 'level-2-only' ? 'L2' : 'L1/L2') ?></span></td>
              <td><span class="badge badge-success" style="font-size:0.65rem;">Up</span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--color-emerald);"><?= (int)($i['hello_interval'] * $i['hello_multiplier']) ?>s</td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ================================================================
     MODAL: Add/Edit IS-IS Interface
     ================================================================ -->
<div class="rt-modal-backdrop" id="isisIfaceModal">
  <div class="rt-modal-dialog">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="isisIfaceTitle">Add IS-IS Interface</span>
      <button type="button" onclick="closeModal('isisIfaceModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="isisIfaceForm" action="<?= base_url('routing/isis/interfaces/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-grid-2">
          <div class="rt-field">
            <label>Interface *</label>
            <select name="interface" id="isisIface" required class="wg-input-mono">
              <?php foreach (($interfaces ?? []) as $if): ?>
                <option value="<?= esc($if['physical_device']) ?>"><?= esc($if['name']) ?> (<?= esc($if['physical_device']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rt-field">
            <label>Circuit Type</label>
            <select name="circuit_type" id="isisCircuit">
              <option value="level-1-2">level-1-2</option>
              <option value="level-1">level-1</option>
              <option value="level-2-only">level-2-only</option>
            </select>
          </div>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field">
            <label>Network Type</label>
            <select name="network_type" id="isisNetType">
              <option value="broadcast">broadcast</option>
              <option value="point-to-point">point-to-point</option>
            </select>
          </div>
          <div class="rt-field"><label>Description</label><input type="text" name="description" id="isisDesc" placeholder="WAN uplink to core"></div>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field"><label>Metric L1</label><input type="number" name="metric_l1" id="isisMetric1" min="0" max="16777215" placeholder="10"></div>
          <div class="rt-field"><label>Metric L2</label><input type="number" name="metric_l2" id="isisMetric2" min="0" max="16777215" placeholder="10"></div>
        </div>
        <div class="rt-grid-3">
          <div class="rt-field"><label>Hello Interval (s)</label><input type="number" name="hello_interval" id="isisHello" min="1" max="600" value="10"></div>
          <div class="rt-field"><label>Hello Multiplier</label><input type="number" name="hello_multiplier" id="isisHelloMult" min="1" max="100" value="3"></div>
          <div class="rt-field"><label>CSNP Interval (s)</label><input type="number" name="csnp_interval" id="isisCsnp" min="1" max="600" placeholder="auto"></div>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field"><label>PSNP Interval (s)</label><input type="number" name="psnp_interval" id="isisPsnp" min="1" max="600" placeholder="auto"></div>
          <div class="rt-field"><label>Password</label><input type="text" name="password" id="isisPassword" placeholder="optional" spellcheck="false"></div>
        </div>
        <label style="display:flex;align-items:center;gap:7px;font-size:0.82rem;color:var(--text-secondary);cursor:pointer;margin-top:4px;">
          <input type="checkbox" name="bfd_enabled" id="isisBfd" value="1"> Enable BFD on this circuit
        </label>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('isisIfaceModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="isisIfaceSubmit">Add Interface</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── IS-IS sub-tab switching ──
function switchIsisSub(name, btn) {
  document.querySelectorAll('.isis-sub-panel').forEach(function(p) { p.classList.remove('active'); });
  document.querySelectorAll('.isis-sub-tab').forEach(function(t) { t.classList.remove('active'); });
  var panel = document.getElementById('isis-sub-' + name);
  if (panel) panel.classList.add('active');
  if (btn) btn.classList.add('active');
  var url = new URL(window.location);
  url.searchParams.set('tab', 'isis');
  url.searchParams.set('sub', name);
  history.replaceState(null, '', url);
}

// ── Auto-activate IS-IS sub-tab from ?sub= param ──
(function() {
  var sub = new URLSearchParams(window.location.search).get('sub');
  if (sub && document.getElementById('isis-sub-' + sub)) {
    var btn = document.querySelector('.isis-sub-tab[onclick*="\'' + sub + '\'"]');
    var p = document.getElementById('isis-sub-' + sub);
    if (btn && p) {
      document.querySelectorAll('.isis-sub-panel').forEach(function(el) { el.classList.remove('active'); });
      document.querySelectorAll('.isis-sub-tab').forEach(function(el) { el.classList.remove('active'); });
      p.classList.add('active');
      btn.classList.add('active');
    }
  }
})();

// ── Toggle the auth password field ──
function toggleIsisAuth() {
  var mode = document.getElementById('isisAuthMode').value;
  document.getElementById('isisAuthPwRow').style.display = (mode === 'none') ? 'none' : '';
}

// ── Add / Edit IS-IS interface ──
function openAddIsisInterface() {
  document.getElementById('isisIfaceTitle').innerText = 'Add IS-IS Interface';
  document.getElementById('isisIfaceForm').action = '<?= base_url('routing/isis/interfaces/store') ?>';
  document.getElementById('isisIfaceSubmit').innerText = 'Add Interface';
  document.getElementById('isisIface').value        = '';
  document.getElementById('isisCircuit').value      = 'level-1-2';
  document.getElementById('isisNetType').value      = 'broadcast';
  document.getElementById('isisDesc').value         = '';
  document.getElementById('isisMetric1').value      = '';
  document.getElementById('isisMetric2').value      = '';
  document.getElementById('isisHello').value        = 10;
  document.getElementById('isisHelloMult').value    = 3;
  document.getElementById('isisCsnp').value         = '';
  document.getElementById('isisPsnp').value         = '';
  document.getElementById('isisPassword').value     = '';
  document.getElementById('isisBfd').checked        = false;
  openModal('isisIfaceModal');
}

function openEditIsisInterface(i) {
  document.getElementById('isisIfaceTitle').innerText = 'Edit IS-IS Interface';
  document.getElementById('isisIfaceForm').action = '<?= base_url('routing/isis/interfaces') ?>/' + encodeURIComponent(i.id) + '/update';
  document.getElementById('isisIfaceSubmit').innerText = 'Update Interface';
  document.getElementById('isisIface').value     = i.interface || '';
  document.getElementById('isisCircuit').value   = i.circuit_type || 'level-1-2';
  document.getElementById('isisNetType').value   = i.network_type || 'broadcast';
  document.getElementById('isisDesc').value      = i.description || '';
  document.getElementById('isisMetric1').value   = (i.metric_l1 !== null && i.metric_l1 !== undefined) ? i.metric_l1 : '';
  document.getElementById('isisMetric2').value   = (i.metric_l2 !== null && i.metric_l2 !== undefined) ? i.metric_l2 : '';
  document.getElementById('isisHello').value     = i.hello_interval || 10;
  document.getElementById('isisHelloMult').value = i.hello_multiplier || 3;
  document.getElementById('isisCsnp').value      = (i.csnp_interval !== null && i.csnp_interval !== undefined) ? i.csnp_interval : '';
  document.getElementById('isisPsnp').value      = (i.psnp_interval !== null && i.psnp_interval !== undefined) ? i.psnp_interval : '';
  document.getElementById('isisPassword').value  = i.password || '';
  document.getElementById('isisBfd').checked     = parseInt(i.bfd_enabled) === 1;
  openModal('isisIfaceModal');
}
</script>
