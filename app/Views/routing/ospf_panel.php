<?php
/**
 * OSPF Comprehensive Panel — included by routing/index.php
 *
 * Variables expected:
 *   $ospfSettings, $ospfAreas, $ospfIfaces, $ospfNetworks,
 *   $ospfNeighbors, $ospfLiveNeighbors, $interfaces
 */

$os  = $ospfSettings  ?? [];
$can = session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? []);

$activeSub = $_GET['sub'] ?? 'general';
$subTabs   = ['general', 'areas', 'interfaces', 'networks', 'neighbors', 'policy', 'status'];
?>

<style>
/* OSPF sub-tab bar */
.ospf-sub-tabs {
  display: flex;
  gap: 0;
  border-bottom: 2px solid var(--color-border);
  margin-bottom: 22px;
  overflow-x: auto;
}
.ospf-sub-tab {
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
.ospf-sub-tab:hover { color: var(--text-primary); }
.ospf-sub-tab.active { color: var(--color-amber); border-bottom-color: var(--color-amber); }

/* label|field rows */
.frr-form { border:1px solid var(--color-border); border-radius:var(--radius-md); overflow:hidden; margin-bottom:20px; }
.frr-section-hdr {
  background: rgba(245,158,11,0.12);
  border-bottom: 1px solid var(--color-border);
  padding: 8px 18px;
  font-size: 0.72rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--color-amber);
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
.frr-input:focus { outline: none; border-color: var(--color-amber); }
.frr-input-sm  { max-width: 140px; }
.frr-input-md  { max-width: 260px; }
.frr-input-mono { font-family: var(--font-mono); }
.frr-select { max-width: 320px; }
.frr-chk {
  display: flex; align-items: center; gap: 8px;
  font-size: 0.83rem; color: var(--text-secondary); margin-top: 2px;
}
.frr-chk input { accent-color: var(--color-amber); width: 16px; height: 16px; }
.frr-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; max-width: 560px; }
.frr-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; max-width: 560px; }
.frr-grid-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 8px; max-width: 600px; }

/* Summary ranges table */
.sr-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.8rem; margin-top: 6px; }
.sr-table th { background: var(--color-surface-3); padding: 6px 10px; text-align: left; font-size: 0.7rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; border-bottom: 1px solid var(--color-border); }
.sr-table td { padding: 5px 8px; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
.sr-table td input { background: var(--color-surface-2); border: 1px solid var(--color-border); color: var(--text-primary); padding: 4px 7px; border-radius: 4px; font-size: 0.8rem; width: 100%; }
.sr-table td input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--color-amber); }
.sr-add-btn { font-size: 0.75rem; margin-top: 6px; }

/* Sub-panel content areas */
.ospf-sub-panel { display: none; }
.ospf-sub-panel.active { display: block; }

/* OSPF status section cards use shared grid rules in ospf.php */
.ospf-status-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; }
.ospf-status-section { min-width:0; margin:0; overflow:hidden; }
.ospf-status-title { padding:10px 14px; border-bottom:1px solid var(--color-border); color:var(--color-amber); font-size:.78rem; font-weight:700; }
.ospf-status-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; }
.ospf-status-section { min-width:0; margin:0; overflow:hidden; }
.ospf-status-title { padding:10px 14px; border-bottom:1px solid var(--color-border); color:var(--color-amber); font-size:.78rem; font-weight:700; }
.ospf-status-section pre {
  max-height:320px; overflow:auto; margin:0; padding:12px 14px; color:var(--text-secondary);
  font: .74rem/1.55 var(--font-mono); white-space:pre; background:transparent; }
.ospf-status-section pre .ospf-status-empty { display:block; color:var(--text-muted); font-style:italic; }
.ospf-status-grid > section:first-child { break-inside:avoid; }
.ospf-status-section + .ospf-status-section { margin-top:12px; }
.ospf-status-empty { display:block; color:var(--text-muted); font-style:italic; }
.ospf-empty-hint { margin-bottom:14px; padding:10px 14px; background:rgba(148,163,184,0.08); border:1px dashed var(--color-border); border-radius:var(--radius-sm); color:var(--text-muted); font-size:0.83rem; }
.ospf-empty-hint > div:first-child { margin-bottom:4px; }
.ospf-empty-hint > div:last-child { font-size:0.78rem; line-height:1.5; }
@media (max-width: 900px) { .ospf-status-grid { grid-template-columns:1fr; } }
@media (max-width: 640px) {
  .frr-row { grid-template-columns: 1fr; }
  .frr-lbl { border-right: none; border-bottom: 1px solid var(--color-border); padding: 8px 14px; }
}
</style>

<!-- ── OSPF Sub-Tab Bar ── -->
<div class="ospf-sub-tabs">
  <?php
    $ospfSubIcons = [
      'general'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
      'areas'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>',
      'interfaces' => '<path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/>',
      'networks'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>',
      'neighbors'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>',
      'policy'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
      'status'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
    ];
    $ospfSubLabels = [
      'general' => 'OSPF', 'areas' => 'Areas', 'interfaces' => 'Interfaces',
      'networks' => 'Networks', 'neighbors' => 'Neighbors', 'policy' => 'Policy', 'status' => 'Status',
    ];
    $ospfSubCounts = [
      'general'    => null,
      'areas'      => count($ospfAreas ?? []),
      'interfaces' => count($ospfIfaces ?? []),
      'networks'   => count($ospfNetworks ?? []),
      'neighbors'  => count($ospfNeighbors ?? []),
      'policy'     => count($prefixLists ?? []) + count($routeMaps ?? []) + count($ospfDistributeLists ?? []) + count($ospfAreaFilters ?? []),
      'status'     => count($ospfLiveNeighbors ?? []),
    ];
    foreach ($subTabs as $sub):
  ?>
    <button type="button" class="ospf-sub-tab <?= $activeSub === $sub ? 'active' : '' ?>"
            onclick="switchOspfSub('<?= $sub ?>', this)">
      <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <?= $ospfSubIcons[$sub] ?>
      </svg>
      <?= $ospfSubLabels[$sub] ?>
      <?php if ($ospfSubCounts[$sub] !== null): ?>
        <span class="rt-tab-count" style="margin-left:5px; font-size:0.67rem; padding:1px 6px;"><?= $ospfSubCounts[$sub] ?></span>
      <?php endif; ?>
    </button>
  <?php endforeach; ?>
</div>

<!-- ================================================================
     SUB-PANEL: OSPF General Settings
     ================================================================ -->
<div class="ospf-sub-panel <?= $activeSub === 'general' ? 'active' : '' ?>" id="ospf-sub-general">
  <form method="POST" action="<?= base_url('routing/ospf/settings/save') ?>">
    <?= csrf_field() ?>

    <!-- General Options -->
    <div class="frr-form">
      <div class="frr-section-hdr">General Options</div>

      <div class="frr-row">
        <div class="frr-lbl">Enable</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="enabled" value="1" <?= !empty($os['enabled']) ? 'checked' : '' ?>>
            Enable OSPF Routing
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Log Adjacency Changes</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="log_adj_changes" value="1" <?= !empty($os['log_adj_changes']) ? 'checked' : '' ?>>
            If set to yes, adjacency changes will be written via syslog.
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Router ID<span class="sub">Optional — defaults to auto</span></div>
        <div class="frr-val">
          <input type="text" name="router_id" class="frr-input frr-input-mono frr-input-md"
                 value="<?= esc($os['router_id'] ?? '')?>" spellcheck="false" placeholder="e.g. 192.168.1.1">
          <p class="hint">A stable identifier for this OSPF router. If left blank, OSPF picks the highest IP on any active interface (frequently a loopback). Set it explicitly so a flapping interface doesn't change your Router ID.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">SPF Hold Time<span class="sub">ms — throttle between SPF runs</span></div>
        <div class="frr-val">
          <input type="number" name="spf_hold_time" class="frr-input frr-input-sm"
                 value="<?= esc($os['spf_hold_time'] ?? 1000) ?>" min="0" max="60000">
          <p class="hint">Minimum gap between two SPF calculations. Prevents SPF from running constantly during a flapping-topology event. Default: 1000 ms. Lower values react faster but burn more CPU during instability.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">SPF Delay<span class="sub">ms — wait before first SPF</span></div>
        <div class="frr-val">
          <input type="number" name="spf_delay" class="frr-input frr-input-sm"
                 value="<?= esc($os['spf_delay'] ?? 200) ?>" min="0" max="600000">
          <p class="hint">How long OSPF waits after receiving a link-state update before starting a new SPF calculation. Default: 200 ms. Together with the hold time this throttles SPF to keep the router responsive under load.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Default Area</div>
        <div class="frr-val">
          <input type="text" name="default_area" class="frr-input frr-input-mono frr-input-sm"
                 value="<?= esc($os['default_area'] ?? '0.0.0.0')?>" spellcheck="false" placeholder="0.0.0.0">
          <p class="hint">The area ID used for interfaces that don't have an explicit area assignment. Area 0.0.0.0 is the backbone — required if you have multi-area OSPF. Most single-area setups can leave this as 0.0.0.0.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Reference Bandwidth<span class="sub">Mbps — cost calculation basis</span></div>
        <div class="frr-val">
          <input type="number" name="reference_bandwidth" class="frr-input frr-input-sm"
                 value="<?= esc(isset($os['reference_bandwidth']) && (int)$os['reference_bandwidth'] !== 100 && (int)$os['reference_bandwidth'] > 0 ? $os['reference_bandwidth'] : '') ?>" placeholder="100 (default)" min="1" max="4294967">
          <p class="hint">OSPF calculates interface cost as reference_bandwidth ÷ interface_bandwidth. The default 100 Mbps makes a 100 Mbps link cost 1 — which means Gigabit and 10G links all cost 1 too. Raise this to 1000 or 10000 so faster links get lower (better) costs and OSPF prefers them correctly.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Max Paths (ECMP)<span class="sub">equal-cost load balancing</span></div>
        <div class="frr-val">
          <input type="number" name="max_paths" class="frr-input frr-input-sm"
                 value="<?= esc(isset($os['max_paths']) && (int)$os['max_paths'] !== 4 && (int)$os['max_paths'] > 0 ? $os['max_paths'] : '') ?>" placeholder="4 (default)" min="1" max="64">
          <p class="hint">How many equal-cost routes OSPF installs into the routing table. 4 is a common default — raise it if you have many redundant equal-cost paths you want used at once.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Passive Default<span class="sub">Hello suppression on all interfaces</span></div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="passive_default" value="1" <?= !empty($os['passive_default']) ? 'checked' : '' ?>>
            Treat all interfaces as passive by default (no Hello packets sent)
          </label>
          <p class="hint">A passive interface still advertises its connected subnet into OSPF, but it doesn't send or listen for Hello packets — so no neighbor adjacency forms on it. Useful when you only want to advertise the subnet on an interface but don't expect to peer with anyone on it (e.g. a LAN segment). Override individual interfaces in the Interfaces tab.</p>
        </div>
      </div>
    </div>

    <!-- Modules -->
    <div class="frr-form">
      <div class="frr-section-hdr">Modules</div>
      <div class="frr-row">
        <div class="frr-lbl">Enable SNMP AgentX</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="snmp_agentx" value="1" <?= !empty($os['snmp_agentx']) ? 'checked' : '' ?>>
            Enable AgentX support for accessing FRR Zebra data via SNMP with the net-snmp package.
          </label>
        </div>
      </div>
    </div>

    <!-- Redistribution -->
    <div class="frr-form">
      <div class="frr-section-hdr">Route Redistribution — inject other sources into OSPF</div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute</div>
        <div class="frr-val" style="flex-direction:row; flex-wrap:wrap; gap:18px;">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_connected" value="1" <?= !empty($os['redistribute_connected']) ? 'checked' : '' ?>>
            Connected routes
          </label>
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_static" value="1" <?= !empty($os['redistribute_static']) ? 'checked' : '' ?>>
            Static routes
          </label>
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_bgp" value="1" <?= !empty($os['redistribute_bgp']) ? 'checked' : '' ?>>
            BGP routes
          </label>
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_rip" value="1" <?= !empty($os['redistribute_rip']) ? 'checked' : '' ?>>
            RIP routes
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribution Route Map<span class="sub">Optional filter</span></div>
        <div class="frr-val">
          <div class="frr-grid-2">
            <div>
              <label style="font-size:0.7rem; color:var(--text-muted); display:block; margin-bottom:4px;">Connected</label>
              <select name="redistribute_connected_rm" class="frr-input frr-select" style="max-width:220px;">
                <option value="">— none —</option>
                <?php foreach (($routeMaps ?? []) as $rm): ?>
                  <option value="<?= esc($rm['name']) ?>" <?= ($os['redistribute_connected_rm'] ?? null) === $rm['name'] ? 'selected' : '' ?>><?= esc($rm['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label style="font-size:0.7rem; color:var(--text-muted); display:block; margin-bottom:4px;">Static</label>
              <select name="redistribute_static_rm" class="frr-input frr-select" style="max-width:220px;">
                <option value="">— none —</option>
                <?php foreach (($routeMaps ?? []) as $rm): ?>
                  <option value="<?= esc($rm['name']) ?>" <?= ($os['redistribute_static_rm'] ?? null) === $rm['name'] ? 'selected' : '' ?>><?= esc($rm['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label style="font-size:0.7rem; color:var(--text-muted); display:block; margin-bottom:4px;">BGP</label>
              <select name="redistribute_bgp_rm" class="frr-input frr-select" style="max-width:220px;">
                <option value="">— none —</option>
                <?php foreach (($routeMaps ?? []) as $rm): ?>
                  <option value="<?= esc($rm['name']) ?>" <?= ($os['redistribute_bgp_rm'] ?? null) === $rm['name'] ? 'selected' : '' ?>><?= esc($rm['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label style="font-size:0.7rem; color:var(--text-muted); display:block; margin-bottom:4px;">RIP</label>
              <select name="redistribute_rip_rm" class="frr-input frr-select" style="max-width:220px;">
                <option value="">— none —</option>
                <?php foreach (($routeMaps ?? []) as $rm): ?>
                  <option value="<?= esc($rm['name']) ?>" <?= ($os['redistribute_rip_rm'] ?? null) === $rm['name'] ? 'selected' : '' ?>><?= esc($rm['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <p class="hint">Attach a route-map to filter which routes from each source get redistributed into OSPF. Leave the route-map blank to permit all matching routes. Create route-maps on the Policy tab.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute Metric<span class="sub">Optional</span></div>
        <div class="frr-val">
          <div class="frr-grid-2">
            <div>
              <label style="font-size:0.7rem; color:var(--text-muted); display:block; margin-bottom:4px;">Metric Value</label>
              <input type="number" name="redistribute_metric" class="frr-input frr-input-sm"
                     value="<?= esc($os['redistribute_metric'] ?? '') ?>" placeholder="optional" min="1" max="16777214">
            </div>
            <div>
              <label style="font-size:0.7rem; color:var(--text-muted); display:block; margin-bottom:4px;">Metric Type</label>
              <select name="redistribute_metric_type" class="frr-input frr-select" style="max-width:160px;">
                <option value="1" <?= ($os['redistribute_metric_type'] ?? 2) == 1 ? 'selected' : '' ?>>Type 1 (E1)</option>
                <option value="2" <?= ($os['redistribute_metric_type'] ?? 2) == 2 ? 'selected' : '' ?>>Type 2 (E2)</option>
              </select>
            </div>
          </div>
          <p class="hint">E2 (default) = only the external cost is considered — the internal path to the ASBR is ignored. E1 = the full path cost (internal + external) — preferred when you have multiple ASBRs advertising the same prefix and want OSPF to pick the closest one.</p>
        </div>
      </div>
    </div>

    <!-- Administrative Distances -->
    <div class="frr-form">
      <div class="frr-section-hdr">Administrative Distance — tweak route preference (optional)</div>
      <div class="frr-row">
        <div class="frr-lbl">Distance<span class="sub">Override default AD</span></div>
        <div class="frr-val">
          <div class="frr-grid-3">
            <div>
              <label style="font-size:0.7rem; color:var(--text-muted); display:block; margin-bottom:4px;">Intra-Area</label>
              <input type="number" name="distance_intra" class="frr-input"
                     value="<?= esc($os['distance_intra'] ?? '') ?>" placeholder="110" min="1" max="255">
            </div>
            <div>
              <label style="font-size:0.7rem; color:var(--text-muted); display:block; margin-bottom:4px;">Inter-Area</label>
              <input type="number" name="distance_inter" class="frr-input"
                     value="<?= esc($os['distance_inter'] ?? '') ?>" placeholder="110" min="1" max="255">
            </div>
            <div>
              <label style="font-size:0.7rem; color:var(--text-muted); display:block; margin-bottom:4px;">External (E1/E2)</label>
              <input type="number" name="distance_external" class="frr-input"
                     value="<?= esc($os['distance_external'] ?? '') ?>" placeholder="110" min="1" max="255">
            </div>
          </div>
          <p class="hint">Administrative Distance is the trustworthiness of a route source — lower wins when the same prefix comes from two sources. Default OSPF AD is 110 (BGP eBGP = 20, iBGP = 200, static = 1, connected = 0). Leave blank to keep the FRR defaults — change only if you need to prefer or deprioritize OSPF relative to another source.</p>
        </div>
      </div>
    </div>

    <?php if ($can): ?>
      <div style="display:flex; justify-content:flex-end; margin-top:4px;">
        <button type="submit" class="btn btn-primary">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          Save OSPF Settings
        </button>
      </div>
    <?php endif; ?>
  </form>
</div>

<!-- ================================================================
     SUB-PANEL: Areas
     ================================================================ -->
<div class="ospf-sub-panel <?= $activeSub === 'areas' ? 'active' : '' ?>" id="ospf-sub-areas">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <span style="font-size:0.875rem; font-weight:700; color:var(--text-primary);">OSPF Areas</span>
    <?php if ($can): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('ospfAreaModal')">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Area
      </button>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:0; overflow-x:auto;">
    <table class="rt-table">
      <thead><tr>
        <th>Area</th><th>Description</th><th>Type</th><th>Auth</th><th>ABR Shortcut</th><th>Summarization</th><th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($ospfAreas)): ?>
          <tr><td colspan="7" style="text-align:center; padding:30px; color:var(--text-muted);">
              <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-amber)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No OSPF areas configured yet.</p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:380px;">Area 0.0.0.0 (the backbone) is always implied. Add named areas when you need multi-area OSPF — stub and NSSA areas limit external route propagation for smaller branch sites.</p>
              </div>
            </td></tr>
        <?php else: ?>
          <?php foreach ($ospfAreas as $area): ?>
            <?php
              $ranges = json_decode($area['summary_ranges'] ?? '[]', true) ?: [];
              $typeClass = match($area['area_type']) {
                'stub'   => 'rt-chip-amber',
                'nssa'   => 'rt-chip-purple',
                default  => 'rt-chip-green',
              };
              $authClass = $area['auth_type'] !== 'none' ? 'rt-chip-blue' : 'rt-chip';
            ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($area['area_id']) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($area['description'] ?: '—') ?></td>
              <td><span class="rt-chip <?= $typeClass ?>"><?= strtoupper(esc($area['area_type'])) ?></span></td>
              <td><span class="rt-chip <?= $authClass ?>"><?= strtoupper(esc($area['auth_type'])) ?></span></td>
              <td><span class="rt-chip"><?= esc($area['abr_shortcut']) ?></span></td>
              <td style="font-size:0.75rem; color:var(--text-muted);"><?= count($ranges) ?> range<?= count($ranges) !== 1 ? 's' : '' ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ($can): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditOspfArea(<?= htmlspecialchars(json_encode($area), ENT_QUOTES) ?>)'>
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/ospf/areas/' . esc($area['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove area <?= esc(addslashes($area['area_id'])) ?>?')"><?= csrf_field() ?>
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
     SUB-PANEL: Interfaces
     ================================================================ -->
<div class="ospf-sub-panel <?= $activeSub === 'interfaces' ? 'active' : '' ?>" id="ospf-sub-interfaces">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <span style="font-size:0.875rem; font-weight:700; color:var(--text-primary);">OSPF Interfaces</span>
    <?php if ($can): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openAddOspfIface()">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Interface
      </button>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:0; overflow-x:auto;">
    <table class="rt-table">
      <thead><tr>
        <th>Interface</th><th>Description</th><th>Network Type</th><th>Area</th><th>Metric</th><th>Hello/Dead</th><th>Auth</th><th>BFD</th><th>Passive</th><th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($ospfIfaces)): ?>
          <tr><td colspan="10" style="text-align:center; padding:30px; color:var(--text-muted);">No OSPF interfaces configured. Add an interface to define per-interface OSPF parameters.</td></tr>
        <?php else: ?>
          <?php foreach ($ospfIfaces as $oif): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($oif['interface']) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($oif['description'] ?: '—') ?></td>
              <td><span class="rt-chip"><?= esc($oif['network_type']) ?></span></td>
              <td><span class="rt-chip rt-chip-amber"><?= esc($oif['area'] ?: 'default') ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);"><?= esc($oif['metric'] ?: 'auto') ?></td>
              <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);"><?= esc($oif['hello_interval']) ?>s / <?= esc($oif['dead_interval']) ?>s</td>
              <td><span class="rt-chip <?= $oif['auth_type'] !== 'none' ? 'rt-chip-blue' : '' ?>"><?= strtoupper(esc($oif['auth_type'])) ?></span></td>
              <td><?= $oif['bfd_enabled'] ? '<span class="badge badge-success" style="font-size:0.65rem;">ON</span>' : '—' ?></td>
              <td><?= $oif['passive'] ? '<span class="badge badge-muted" style="font-size:0.65rem;">passive</span>' : '—' ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ($can): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditOspfIface(<?= htmlspecialchars(json_encode($oif), ENT_QUOTES) ?>)'>
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/ospf/ifaces/' . esc($oif['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove OSPF config for <?= esc(addslashes($oif['interface'])) ?>?')"><?= csrf_field() ?>
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
     SUB-PANEL: Networks
     ================================================================ -->
<div class="ospf-sub-panel <?= $activeSub === 'networks' ? 'active' : '' ?>" id="ospf-sub-networks">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <span style="font-size:0.875rem; font-weight:700; color:var(--text-primary);">OSPF Network Statements</span>
    <?php if ($can): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('ospfNetModal')">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Network
      </button>
    <?php endif; ?>
  </div>

  <p style="font-size:0.78rem; color:var(--text-muted); margin-bottom:12px;">
    Network statements tell OSPF which interfaces to participate on. Format: <code>network X.X.X.X/mask area Y.Y.Y.Y</code>
  </p>

  <div class="card" style="padding:0; overflow-x:auto;">
    <table class="rt-table">
      <thead><tr><th>Network</th><th>Area</th><th>Description</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($ospfNetworks)): ?>
          <tr><td colspan="4" style="text-align:center; padding:28px; color:var(--text-muted);">No OSPF network statements defined.</td></tr>
        <?php else: ?>
          <?php foreach ($ospfNetworks as $on): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($on['network']) ?></span></td>
              <td><span class="rt-chip rt-chip-amber"><?= esc($on['area']) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($on['description'] ?: '—') ?></td>
              <td style="text-align:right;">
                <?php if ($can): ?>
                  <form method="POST" action="<?= base_url('routing/ospf/networks/' . esc($on['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove network statement?')"><?= csrf_field() ?>
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
     SUB-PANEL: Neighbors (manual / NBMA)
     ================================================================ -->
<div class="ospf-sub-panel <?= $activeSub === 'neighbors' ? 'active' : '' ?>" id="ospf-sub-neighbors">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <span style="font-size:0.875rem; font-weight:700; color:var(--text-primary);">Manual OSPF Neighbors</span>
    <?php if ($can): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('ospfNbrModal')">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Neighbor
      </button>
    <?php endif; ?>
  </div>
  <p style="font-size:0.78rem; color:var(--text-muted); margin-bottom:12px;">
    Manual neighbors are needed for NBMA or point-to-multipoint non-broadcast networks where OSPF cannot auto-discover peers via multicast.
  </p>

  <div class="card" style="padding:0; overflow-x:auto; margin-bottom:24px;">
    <table class="rt-table">
      <thead><tr><th>Neighbor IP</th><th>Area</th><th>Priority</th><th>Poll Interval</th><th>Description</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($ospfNeighbors)): ?>
          <tr><td colspan="6" style="text-align:center; padding:28px; color:var(--text-muted);">No manual neighbors configured.</td></tr>
        <?php else: ?>
          <?php foreach ($ospfNeighbors as $nb): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($nb['neighbor_ip']) ?></span></td>
              <td><span class="rt-chip rt-chip-amber"><?= esc($nb['area'] ?: 'default') ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);"><?= esc($nb['priority'] ?? '—') ?></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);"><?= esc($nb['poll_interval'] ? $nb['poll_interval'] . 's' : '—') ?></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($nb['description'] ?: '—') ?></td>
              <td style="text-align:right;">
                <?php if ($can): ?>
                  <form method="POST" action="<?= base_url('routing/ospf/neighbors/' . esc($nb['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove neighbor?')"><?= csrf_field() ?>
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
     SUB-PANEL: Policy (Prefix-Lists & Route-Maps)
     ================================================================ -->
<div class="ospf-sub-panel <?= $activeSub === 'policy' ? 'active' : '' ?>" id="ospf-sub-policy">

  <p style="font-size:0.78rem; color:var(--text-muted); margin-bottom:14px;">
    Prefix-lists select which networks a route-map matches. A route-map is then applied to an OSPF
    redistribution source on the <a href="#" onclick="switchOspfSub('general', document.querySelector('.ospf-sub-tab[onclick*=general]')); return false;" style="color:var(--color-cyan);">OSPF tab</a>
    (e.g. <code class="wg-input-mono">redistribute static route-map RM_INTERNAL_STATIC</code>).
    Distribute-lists filter routes accepted from or advertised to neighbors; area filter-lists filter
    inter-area (type-3) LSAs into or out of an area.
    The <strong>Used By</strong> column lists every place an object is referenced — an object that is
    still in use cannot be deleted until those references are removed.
  </p>

  <?php if (!empty($ospfStaleRefs)): ?>
    <div class="alert alert-danger animate-in" data-persist="1" style="margin-bottom:20px; flex-direction:column; align-items:flex-start; gap:6px;">
      <strong>
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:-2px; margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
        <?= count($ospfStaleRefs) ?> stale OSPF policy reference<?= count($ospfStaleRefs) !== 1 ? 's' : '' ?> detected
      </strong>
      <span style="font-size:0.78rem; font-weight:400;">
        The following OSPF references point at a route-map or prefix-list that no longer exists.
        FRR would reject or silently ignore the compiled config — recreate the object or remove the reference.
      </span>
      <ul style="margin:6px 0 0; padding-left:18px; font-size:0.76rem; font-family:var(--font-mono);">
        <?php foreach ($ospfStaleRefs as $stale): ?>
          <li>
            <strong><?= esc($stale['type']) ?></strong>
            <span style="color:var(--color-red);"><?= esc($stale['name']) ?></span>
            missing — referenced by <?= esc($stale['referenced_by']) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- Prefix Lists -->
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
    <span style="font-size:0.875rem; font-weight:700; color:var(--text-primary);">Prefix Lists</span>
    <?php if ($can): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openAddPrefixList()">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Prefix List
      </button>
    <?php endif; ?>
  </div>
  <div class="card" style="padding:0; overflow-x:auto; margin-bottom:26px;">
    <table class="rt-table">
      <thead><tr><th>Name</th><th>Description</th><th>Rules</th><th>Used By</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($prefixLists)): ?>
          <tr><td colspan="5" style="text-align:center; padding:28px; color:var(--text-muted);">No prefix-lists defined. Add one to match networks in a route-map.</td></tr>
        <?php else: ?>
          <?php foreach ($prefixLists as $pl): ?>
            <?php
              $plRules = json_decode($pl['rules'] ?? '[]', true) ?: [];
              $plUses  = array_values(array_unique($prefixListRefs[$pl['name']] ?? []));
              $plInUse = $plUses !== [];
            ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($pl['name']) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($pl['description'] ?: '—') ?></td>
              <td style="font-size:0.75rem; color:var(--text-muted);"><?= count($plRules) ?> rule<?= count($plRules) !== 1 ? 's' : '' ?></td>
              <td style="font-size:0.72rem; max-width:280px;">
                <?php if (! $plInUse): ?>
                  <span style="color:var(--text-muted);">Not referenced</span>
                <?php else: ?>
                  <?php foreach ($plUses as $use): ?>
                    <span class="badge badge-info" style="font-size:0.62rem; margin:1px 3px 1px 0; display:inline-block;"><?= esc($use) ?></span>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ($can): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" title="Edit" onclick='openEditPrefixList(<?= htmlspecialchars(json_encode($pl, ENT_QUOTES), ENT_QUOTES) ?>)'>
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <?php if ($plInUse): ?>
                    <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--text-muted); cursor:not-allowed;" disabled title="In use — remove the references listed under Used By first">
                      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                  <?php else: ?>
                    <form method="POST" action="<?= base_url('routing/ospf/policy/prefix-lists/' . esc($pl['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove prefix-list <?= esc(addslashes($pl['name'])) ?>?')"><?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);">
                        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                      </button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Route Maps -->
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
    <span style="font-size:0.875rem; font-weight:700; color:var(--text-primary);">Route Maps</span>
    <?php if ($can): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openAddRouteMap()">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Route Map
      </button>
    <?php endif; ?>
  </div>
  <div class="card" style="padding:0; overflow-x:auto;">
    <table class="rt-table">
      <thead><tr><th>Name</th><th>Description</th><th>Rules</th><th>Matches</th><th>Used By</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($routeMaps)): ?>
          <tr><td colspan="6" style="text-align:center; padding:28px; color:var(--text-muted);">No route-maps defined. Add one to filter OSPF redistribution.</td></tr>
        <?php else: ?>
          <?php foreach ($routeMaps as $rm): ?>
            <?php
              $rmRules = json_decode($rm['rules'] ?? '[]', true) ?: [];
              $rmMatches = [];
              foreach ($rmRules as $rr) {
                  if (!empty($rr['match_prefix_list'])) { $rmMatches[] = $rr['match_prefix_list']; }
              }
              $rmUses  = array_values(array_unique($routeMapRefs[$rm['name']] ?? []));
              $rmInUse = $rmUses !== [];
            ?>
            <tr>
              <td><span class="rt-chip rt-chip-purple"><?= esc($rm['name']) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($rm['description'] ?: '—') ?></td>
              <td style="font-size:0.75rem; color:var(--text-muted);"><?= count($rmRules) ?> rule<?= count($rmRules) !== 1 ? 's' : '' ?></td>
              <td style="font-size:0.75rem; color:var(--text-muted);"><?= $rmMatches ? esc(implode(', ', array_unique($rmMatches))) : '—' ?></td>
              <td style="font-size:0.72rem; max-width:280px;">
                <?php if (! $rmInUse): ?>
                  <span style="color:var(--text-muted);">Not referenced</span>
                <?php else: ?>
                  <?php foreach ($rmUses as $use): ?>
                    <span class="badge badge-info" style="font-size:0.62rem; margin:1px 3px 1px 0; display:inline-block;"><?= esc($use) ?></span>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ($can): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" title="Edit" onclick='openEditRouteMap(<?= htmlspecialchars(json_encode($rm, ENT_QUOTES), ENT_QUOTES) ?>)'>
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <?php if ($rmInUse): ?>
                    <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--text-muted); cursor:not-allowed;" disabled title="In use — remove the references listed under Used By first">
                      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                  <?php else: ?>
                    <form method="POST" action="<?= base_url('routing/ospf/policy/route-maps/' . esc($rm['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove route-map <?= esc(addslashes($rm['name'])) ?>?')"><?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);">
                        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                      </button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Distribute Lists -->
  <div style="display:flex; justify-content:space-between; align-items:center; margin:26px 0 10px;">
    <span style="font-size:0.875rem; font-weight:700; color:var(--text-primary);">OSPF Distribute Lists</span>
    <?php if ($can): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openAddDistributeList()">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Distribute List
      </button>
    <?php endif; ?>
  </div>
  <div class="card" style="padding:0; overflow-x:auto; margin-bottom:26px;">
    <table class="rt-table">
      <thead><tr><th>Prefix List</th><th>Direction</th><th>Interface</th><th>Description</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($ospfDistributeLists)): ?>
          <tr><td colspan="5" style="text-align:center; padding:28px; color:var(--text-muted);">No distribute-lists. Add one to filter the routes OSPF accepts or advertises.</td></tr>
        <?php else: ?>
          <?php foreach ($ospfDistributeLists as $dl): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($dl['prefix_list']) ?></span></td>
              <td><span class="rt-chip <?= $dl['direction'] === 'out' ? 'rt-chip-amber' : 'rt-chip-green' ?>"><?= esc(strtoupper($dl['direction'])) ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);"><?= esc($dl['interface'] ?: 'any') ?></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($dl['description'] ?: '—') ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ($can): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" title="Edit" onclick='openEditDistributeList(<?= htmlspecialchars(json_encode($dl, ENT_QUOTES), ENT_QUOTES) ?>)'>
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/ospf/policy/distribute-lists/' . esc($dl['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove this distribute-list?')"><?= csrf_field() ?>
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

  <!-- Area Filter Lists -->
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
    <span style="font-size:0.875rem; font-weight:700; color:var(--text-primary);">Area Filter Lists</span>
    <?php if ($can): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openAddAreaFilter()">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Area Filter
      </button>
    <?php endif; ?>
  </div>
  <p style="font-size:0.78rem; color:var(--text-muted); margin:0 0 10px;">
    Area filter-lists require this router to be an Area Border Router; they filter type-3 summary LSAs into or out of an area.
  </p>
  <div class="card" style="padding:0; overflow-x:auto;">
    <table class="rt-table">
      <thead><tr><th>Area</th><th>Prefix List</th><th>Direction</th><th>Description</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($ospfAreaFilters)): ?>
          <tr><td colspan="5" style="text-align:center; padding:28px; color:var(--text-muted);">No area filter-lists defined.</td></tr>
        <?php else: ?>
          <?php foreach ($ospfAreaFilters as $af): ?>
            <tr>
              <td><span class="rt-chip rt-chip-amber"><?= esc($af['area_id']) ?></span></td>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($af['prefix_list']) ?></span></td>
              <td><span class="rt-chip <?= $af['direction'] === 'out' ? 'rt-chip-amber' : 'rt-chip-green' ?>"><?= esc(strtoupper($af['direction'])) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($af['description'] ?: '—') ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ($can): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" title="Edit" onclick='openEditAreaFilter(<?= htmlspecialchars(json_encode($af, ENT_QUOTES), ENT_QUOTES) ?>)'>
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/ospf/policy/area-filters/' . esc($af['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove this area filter-list?')"><?= csrf_field() ?>
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

  <!-- Compiled FRR Policy Preview (read-only) -->
  <div style="display:flex; justify-content:space-between; align-items:center; margin:26px 0 10px;">
    <div>
      <span style="font-size:0.875rem; font-weight:700; color:var(--text-primary);">Compiled FRR Policy</span>
      <span style="font-size:0.72rem; color:var(--text-muted); margin-left:8px;">read-only preview</span>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" id="ospfPolicyCopyBtn" onclick="copyOspfPolicyPreview()">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
      Copy
    </button>
  </div>
  <p style="font-size:0.78rem; color:var(--text-muted); margin:0 0 10px;">
    The lines below are compiled from the objects above and are shown for review only — they are not
    applied from here. Prefix-lists and route-maps are global; distribute-lists and area filter-lists
    are rendered inside <code class="wg-input-mono">router ospf</code>.
  </p>
  <div class="card" style="padding:0; overflow-x:auto;">
    <pre id="ospfPolicyPreview" style="margin:0; padding:16px 18px; font-family:var(--font-mono); font-size:0.76rem; line-height:1.6; color:var(--text-secondary); white-space:pre; overflow-x:auto;"><?= esc($ospfPolicyPreview ?? '') ?></pre>
  </div>
</div>

<!-- ================================================================
     SUB-PANEL: Live OSPF Status
     ================================================================ -->
<div class="ospf-sub-panel <?= $activeSub === 'status' ? 'active' : '' ?>" id="ospf-sub-status">
  <?php if (!empty($isMock)): ?>
    <div class="alert alert-info" style="margin-bottom:16px;">Simulation mode — OSPF status output is simulated.</div>
  <?php endif; ?>
  <div id="ospf-empty-hint" class="ospf-empty-hint"
       style="display:<?= empty($ospfStatusSections) || empty(array_filter($ospfStatusSections, fn($s) => !empty($s['output']))) ? '' : 'none' ?>;">
    <div>OSPF status is not available yet.</div>
    <div>
      <?php if (empty($ospfStatusSections)): ?>
        The daemon has not returned any OSPF diagnostics.
      <?php else: ?>
        All collected OSPF sections are currently empty.
      <?php endif; ?>
      Click <strong>Refresh Status</strong> to poll the routing daemon again.
    </div>
  </div>
  <?php
    $anyRenderedServer = !empty($ospfStatusSections) && array_reduce(array_keys($ospfSectionTitles), function($carry, $k) use ($ospfStatusSections) {
      return $carry || !empty($ospfStatusSections[$k]['output']);
    }, false);
  ?>
  <div id="ospf-ajax-rendered" data-rendered="<?= $anyRenderedServer ? '1' : '0' ?>" style="display:none;"></div>
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
    <div style="font-size:0.83rem; color:var(--text-secondary); flex:1; min-width:200px;">
      Live diagnostics from FRRouting <code>ospfd</code> via <code>vtysh</code>
    </div>
    <button type="button" class="btn btn-secondary btn-sm" id="btnRefreshOspfStatus" onclick="refreshOspfStatus(this)" title="Fetch live OSPF diagnostics from the routing daemon">
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
      Refresh Status
    </button>
  </div>
  <?php
    $ospfStatusSections = is_array($ospfStatus['sections'] ?? null) ? $ospfStatus['sections'] : [];
    $ospfResources = is_array($ospfStatus['resources'] ?? null) ? $ospfStatus['resources'] : [];
    $ospfSectionTitles = [
      'general' => 'OSPF General',
      'neighbors' => 'OSPF Neighbors',
      'routes' => 'OSPF Routes',
      'database' => 'OSPF Database',
      'router_database' => 'OSPF Router Database',
      'interfaces' => 'OSPF Interfaces',
    ];
  ?>
  <div class="ospf-status-grid">
    <?php foreach ($ospfSectionTitles as $sectionKey => $sectionTitle): ?>
      <section class="card ospf-status-section">
        <div class="ospf-status-title"><?= esc($sectionTitle) ?></div>
        <pre id="ospf-status-<?= esc($sectionKey) ?>"><?= esc((string) ($ospfStatusSections[$sectionKey]['output'] ?? 'OSPF status is unavailable.')) ?></pre>
      </section>
    <?php endforeach; ?>
    <section class="card ospf-status-section">
      <div class="ospf-status-title">OSPF CPU Usage</div>
      <pre id="ospf-status-cpu"><?= esc((string) ($ospfResources['cpu_output'] ?? 'ospfd CPU usage is unavailable.')) ?></pre>
    </section>
    <section class="card ospf-status-section">
      <div class="ospf-status-title">OSPF Memory</div>
      <pre id="ospf-status-memory"><?= esc((string) ($ospfResources['memory_output'] ?? 'ospfd memory usage is unavailable.')) ?></pre>
    </section>
  </div>
</div>

<!-- ================================================================
     MODALS for OSPF
     ================================================================ -->

<!-- OSPF Area Modal -->
<div class="rt-modal-backdrop" id="ospfAreaModal">
  <div class="rt-modal-dialog" style="max-width:720px;">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="ospfAreaModalTitle">Add OSPF Area</span>
      <button type="button" onclick="closeModal('ospfAreaModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="ospfAreaForm" action="<?= base_url('routing/ospf/areas/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="padding:0; overflow-y:auto; flex:1; min-height:0;">

        <!-- Area Options -->
        <div class="frr-form" style="border:none; border-radius:0; border-bottom:1px solid var(--color-border);">
          <div class="frr-section-hdr">Area Options</div>
          <div class="frr-row">
            <div class="frr-lbl">Area *<span class="sub">ID for this area</span></div>
            <div class="frr-val">
              <input type="text" name="area_id" id="areaId" class="frr-input frr-input-mono frr-input-md" required placeholder="0.0.0.0" spellcheck="false">
              <p class="hint">The ID for this area. Use dotted-decimal notation (0.0.0.0) or an integer.</p>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Description</div>
            <div class="frr-val">
              <input type="text" name="description" id="areaDesc" class="frr-input frr-input-md" placeholder="Backbone / Core LAN">
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Area Type<span class="sub">Defines behavior</span></div>
            <div class="frr-val">
              <select name="area_type" id="areaType" class="frr-input frr-select">
                <option value="normal">Normal (default)</option>
                <option value="stub">Stub</option>
                <option value="nssa">NSSA (Not-So-Stubby)</option>
              </select>
              <p class="hint">Defines how this area behaves. Stub/NSSA areas do not receive AS-external LSAs.</p>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Default Route Cost<span class="sub">0–16777215</span></div>
            <div class="frr-val">
              <input type="number" name="default_cost" id="areaDefCost" class="frr-input frr-input-sm" placeholder="blank = auto" min="0" max="16777215">
              <p class="hint">Cost applied to default route summary LSA messages sent to stub areas.</p>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">ABR Shortcut</div>
            <div class="frr-val">
              <select name="abr_shortcut" id="areaAbr" class="frr-input frr-select">
                <option value="default">Default</option>
                <option value="enable">Enable</option>
                <option value="disable">Disable</option>
              </select>
              <p class="hint">Advertises the area as capable of using ABR shortcut behavior; if enabled in the OSPF Advanced Settings.</p>
            </div>
          </div>
          <div class="frr-row" style="border-bottom:none;">
            <div class="frr-lbl">No Summary</div>
            <div class="frr-val">
              <label class="frr-chk">
                <input type="checkbox" name="no_summary" id="areaNoSum" value="1">
                Do not send summary LSAs into this stub area (totally stub)
              </label>
            </div>
          </div>
        </div>

        <!-- Authentication -->
        <div class="frr-form" style="border:none; border-radius:0; border-bottom:1px solid var(--color-border);">
          <div class="frr-section-hdr">Authentication</div>
          <div class="frr-row" style="border-bottom:none;">
            <div class="frr-lbl">Authentication Type</div>
            <div class="frr-val">
              <select name="auth_type" id="areaAuthType" class="frr-input frr-select">
                <option value="none">None (default)</option>
                <option value="password">Simple Password</option>
                <option value="md5">Message Digest (MD5)</option>
                <option value="sha256">SHA-256</option>
              </select>
              <p class="hint">The type of OSPF authentication used by all routers in this area. This type must match all neighbors. Using Message Digest is the best practice as Simple Password authentication is weak.</p>
            </div>
          </div>
        </div>

        <!-- Route Summarization -->
        <div class="frr-form" style="border:none; border-radius:0;">
          <div class="frr-section-hdr">Route Summarization (ABR)</div>
          <div class="frr-row" style="border-bottom:none;">
            <div class="frr-lbl">Summary Ranges</div>
            <div class="frr-val">
              <p class="hint" style="margin-bottom:8px;">When attached to more than one area, this router acts as an Area Border Router (ABR). An ABR can choose to advertise a summary route instead of individual interface routes. Only one of the options for each prefix may be activated.</p>
              <table class="sr-table" id="srTable">
                <thead>
                  <tr>
                    <th>Summary Prefix</th>
                    <th>Do Not Advertise</th>
                    <th>Cost (0–16777215)</th>
                    <th>Substitute Prefix</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody id="srBody">
                  <!-- JS-generated rows -->
                </tbody>
              </table>
              <button type="button" class="btn btn-ghost btn-sm sr-add-btn" onclick="addSrRow()">+ Add</button>
            </div>
          </div>
        </div>

      </div><!-- /.rt-modal-body -->
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('ospfAreaModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="ospfAreaSubmit">Save Area</button>
      </div>
    </form>
  </div>
</div>

<!-- OSPF Interface Modal -->
<div class="rt-modal-backdrop" id="ospfIfaceModal">
  <div class="rt-modal-dialog" style="max-width:740px;">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="ospfIfaceModalTitle">Add OSPF Interface</span>
      <button type="button" onclick="closeModal('ospfIfaceModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="ospfIfaceForm" action="<?= base_url('routing/ospf/ifaces/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="padding:0; overflow-y:auto; flex:1; min-height:0;">

        <!-- Interface Options -->
        <div class="frr-form" style="border:none; border-radius:0; border-bottom:1px solid var(--color-border);">
          <div class="frr-section-hdr">Interface Options</div>
          <div class="frr-row">
            <div class="frr-lbl">Interface *</div>
            <div class="frr-val">
              <select name="interface" id="oifIface" class="frr-input frr-select" onchange="onOspfIfaceChange(this.value)">
                <optgroup label="Physical & System Interfaces">
                  <?php foreach ($interfaces as $if): ?>
                    <option value="<?= esc($if['physical_device'] ?: $if['name']) ?>"><?= esc($if['name']) ?> (<?= esc($if['physical_device'] ?: $if['name']) ?>)</option>
                  <?php endforeach; ?>
                  <option value="lo">lo (Loopback)</option>
                </optgroup>
                <?php if (!empty($vpnInterfaces['wireguard'])): ?>
                  <optgroup label="WireGuard VPN Tunnels">
                    <?php foreach ($vpnInterfaces['wireguard'] as $wg): ?>
                      <option value="<?= esc($wg['physical_device']) ?>"><?= esc($wg['name']) ?> (<?= esc($wg['physical_device']) ?><?= !empty($wg['ipv4_address']) ? ' — ' . esc($wg['ipv4_address']) : '' ?>)</option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
                <?php if (!empty($vpnInterfaces['openvpn'])): ?>
                  <optgroup label="OpenVPN Tunnels">
                    <?php foreach ($vpnInterfaces['openvpn'] as $ovpn): ?>
                      <option value="<?= esc($ovpn['physical_device']) ?>"><?= esc($ovpn['name']) ?> (<?= esc($ovpn['physical_device']) ?><?= !empty($ovpn['ipv4_address']) ? ' — ' . esc($ovpn['ipv4_address']) : '' ?>)</option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
                <?php if (!empty($vpnInterfaces['ipsec'])): ?>
                  <optgroup label="IPsec VTI Tunnels">
                    <?php foreach ($vpnInterfaces['ipsec'] as $ipsec): ?>
                      <option value="<?= esc($ipsec['physical_device']) ?>"><?= esc($ipsec['name']) ?> (<?= esc($ipsec['physical_device']) ?><?= !empty($ipsec['ipv4_address']) ? ' — ' . esc($ipsec['ipv4_address']) : '' ?>)</option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
                <optgroup label="Custom / Other Device">
                  <option value="__custom__">Custom Device Entry…</option>
                </optgroup>
              </select>
              <div id="oifCustomWrap" style="display:none; margin-top:8px;">
                <input type="text" name="interface_custom" id="oifCustomInput" class="frr-input frr-input-md frr-input-mono" placeholder="e.g. wg538, tun0, vti0, eth3" spellcheck="false">
                <p class="hint" style="margin-top:4px;">Specify any valid Linux kernel network interface device name.</p>
              </div>
              <p class="hint">Select a physical or VPN tunnel interface (WireGuard, OpenVPN, IPsec VTI), or enter a custom device name.</p>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Description</div>
            <div class="frr-val">
              <input type="text" name="description" id="oifDesc" class="frr-input frr-input-md" placeholder="WAN uplink / LAN backbone">
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Network Type</div>
            <div class="frr-val">
              <select name="network_type" id="oifNetType" class="frr-input frr-select">
                <option value="broadcast">Broadcast</option>
                <option value="nbma">NBMA (Non-Broadcast Multi-Access)</option>
                <option value="point-to-multipoint">Point-to-Multipoint</option>
                <option value="point-to-point">Point-to-Point</option>
                <option value="loopback">Loopback</option>
              </select>
              <p class="hint">Select OSPF Network Type of the interface.</p>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Area<span class="sub">Leave blank for default</span></div>
            <div class="frr-val">
              <input type="text" name="area" id="oifArea" class="frr-input frr-input-mono frr-input-sm" placeholder="0.0.0.0" spellcheck="false">
              <p class="hint">The area for this interface (leave blank for default area).</p>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Metric<span class="sub">Optional</span></div>
            <div class="frr-val">
              <input type="number" name="metric" id="oifMetric" class="frr-input frr-input-sm" placeholder="auto" min="1" max="65535">
              <p class="hint">Interface cost. If blank, auto-calculated from reference bandwidth.</p>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Interface is Passive</div>
            <div class="frr-val">
              <label class="frr-chk">
                <input type="checkbox" name="passive" id="oifPassive" value="1">
                Prevent transmission and reception of OSPF packets on this interface. The specified interface will be announced as a stub network.
              </label>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Ignore MTU</div>
            <div class="frr-val">
              <label class="frr-chk">
                <input type="checkbox" name="ignore_mtu" id="oifIgnMtu" value="1">
                Ignore MTU values for OSPF peers on this interface. Allows OSPF to form full adjacencies even when there is an MTU mismatch.
              </label>
            </div>
          </div>
          <div class="frr-row" style="border-bottom:none;">
            <div class="frr-lbl">Accept Filter</div>
            <div class="frr-val">
              <label class="frr-chk">
                <input type="checkbox" name="accept_filter" id="oifAccept" value="1">
                Prevent routes for this interface subnet or IP address from being distributed by OSPF (Suggested for Multi-WAN environments).
              </label>
            </div>
          </div>
        </div>

        <!-- Authentication -->
        <div class="frr-form" style="border:none; border-radius:0; border-bottom:1px solid var(--color-border);">
          <div class="frr-section-hdr">Authentication</div>
          <div class="frr-row">
            <div class="frr-lbl">Authentication Type</div>
            <div class="frr-val">
              <select name="auth_type" id="oifAuthType" class="frr-input frr-select" onchange="toggleAuthPw(this.value)">
                <option value="none">None (default)</option>
                <option value="password">Simple Password</option>
                <option value="md5">Message Digest (MD5)</option>
                <option value="sha256">SHA-256</option>
              </select>
              <p class="hint">The type of OSPF authentication to use on this interface. This type must match all neighbors reachable through this interface. Using Message Digest is the best practice as Simple Password authentication is weak.</p>
            </div>
          </div>
          <div class="frr-row" id="oifPwRow" style="border-bottom:none; display:none;">
            <div class="frr-lbl">Password<span class="sub">Simple / MD5</span></div>
            <div class="frr-val">
              <input type="password" name="auth_password" id="oifAuthPw" class="frr-input frr-input-md" placeholder="OSPF shared secret" autocomplete="new-password" spellcheck="false">
              <p class="hint">Password to use with Simple Password or Message Digest authentication. This value must match all neighbors reachable through this interface. Simple passwords may be up to 8 characters; Message Digest passwords (keys) may be up to 16 characters.</p>
            </div>
          </div>
        </div>

        <!-- Advanced Timers -->
        <div class="frr-form" style="border:none; border-radius:0; border-bottom:1px solid var(--color-border);">
          <div class="frr-section-hdr">Advanced Timers</div>
          <div class="frr-row">
            <div class="frr-lbl">Router Priority<span class="sub">0–255</span></div>
            <div class="frr-val">
              <input type="number" name="router_priority" id="oifPriority" class="frr-input frr-input-sm" value="1" min="0" max="255">
              <p class="hint">Router priority when participating in elections for DR (0–255, Default 1). 0 will cause the router to not participate in election.</p>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Retransmit Interval<span class="sub">seconds</span></div>
            <div class="frr-val">
              <input type="number" name="retransmit_interval" id="oifRetransmit" class="frr-input frr-input-sm" value="5" min="3" max="65535">
              <p class="hint">Retransmit Interval for this OSPF interface in seconds (3–65535, Default 5).</p>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Hello Interval<span class="sub">seconds</span></div>
            <div class="frr-val">
              <input type="number" name="hello_interval" id="oifHello" class="frr-input frr-input-sm" value="10" min="1" max="65535">
              <p class="hint">Hello Interval for this OSPF interface in seconds (1–65535, Default 10). Must match all neighbors.</p>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Dead Interval<span class="sub">seconds</span></div>
            <div class="frr-val">
              <input type="number" name="dead_interval" id="oifDead" class="frr-input frr-input-sm" value="40" min="1" max="65535">
              <p class="hint">Dead Interval for this OSPF interface in seconds (1–65535, Default 40). Must match all neighbors.</p>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Transmit Delay<span class="sub">seconds</span></div>
            <div class="frr-val">
              <input type="number" name="transmit_delay" id="oifTxDelay" class="frr-input frr-input-sm" value="1" min="1" max="65535">
              <p class="hint">Estimated time to transmit a link-state update packet on this interface (1–65535, Default 1).</p>
            </div>
          </div>
          <div class="frr-row" style="border-bottom:none;">
            <div class="frr-lbl">Minimal Hello<span class="sub">Optional multiplier</span></div>
            <div class="frr-val">
              <input type="number" name="minimal_hello" id="oifMinHello" class="frr-input frr-input-sm" placeholder="disabled" min="1" max="10">
              <p class="hint">Minimal Hello multiplier for this OSPF interface in seconds (1–10, Default disabled). Overrides the Hello Interval and Dead Interval and instead sends this number of Hello messages per second.</p>
            </div>
          </div>
        </div>

        <!-- BFD -->
        <div class="frr-form" style="border:none; border-radius:0;">
          <div class="frr-section-hdr">BFD</div>
          <div class="frr-row" style="border-bottom:none;">
            <div class="frr-lbl">BFD Enable</div>
            <div class="frr-val">
              <label class="frr-chk">
                <input type="checkbox" name="bfd_enabled" id="oifBfd" value="1">
                Listen for BFD events on peers created on the interface. When OSPF finds a new neighbor, it creates a BFD peer to monitor the link status for fast convergence.
              </label>
            </div>
          </div>
        </div>

      </div><!-- /.rt-modal-body -->
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('ospfIfaceModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="ospfIfaceSubmit">Save Interface</button>
      </div>
    </form>
  </div>
</div>

<!-- OSPF Network Statement Modal -->
<div class="rt-modal-backdrop" id="ospfNetModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">Add OSPF Network Statement</span>
      <button type="button" onclick="closeModal('ospfNetModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('routing/ospf/networks/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-field"><label>Network (CIDR) *</label><input type="text" name="network" required placeholder="192.168.1.0/24" class="wg-input-mono" spellcheck="false"></div>
        <div class="rt-field"><label>Area</label><input type="text" name="area" value="0.0.0.0" placeholder="0.0.0.0" class="wg-input-mono" spellcheck="false"><p class="hint">Area ID in dotted decimal</p></div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" placeholder="LAN backbone"></div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('ospfNetModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Add Network</button>
      </div>
    </form>
  </div>
</div>

<!-- OSPF Neighbor Modal -->
<div class="rt-modal-backdrop" id="ospfNbrModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">Add Manual OSPF Neighbor</span>
      <button type="button" onclick="closeModal('ospfNbrModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('routing/ospf/neighbors/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-field"><label>Neighbor IP *</label><input type="text" name="neighbor_ip" required placeholder="10.0.0.2" class="wg-input-mono" spellcheck="false"></div>
        <div class="rt-grid-2">
          <div class="rt-field"><label>Area</label><input type="text" name="area" placeholder="0.0.0.0" class="wg-input-mono" spellcheck="false"></div>
          <div class="rt-field"><label>Priority</label><input type="number" name="priority" placeholder="0" min="0" max="255"></div>
        </div>
        <div class="rt-field"><label>Poll Interval (s)</label><input type="number" name="poll_interval" placeholder="120 (NBMA default)" min="1"><p class="hint" style="margin-top:3px;">NBMA networks only. How often to poll inactive neighbors.</p></div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" placeholder="Remote branch router"></div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('ospfNbrModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Add Neighbor</button>
      </div>
    </form>
  </div>
</div>

<!-- OSPF Prefix-List Modal -->
<div class="rt-modal-backdrop" id="ospfPlModal">
  <div class="rt-modal-dialog" style="max-width:860px;">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="ospfPlModalTitle">Add Prefix List</span>
      <button type="button" onclick="closeModal('ospfPlModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="ospfPlForm" action="<?= base_url('routing/ospf/policy/prefix-lists/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-grid-2">
          <div class="rt-field"><label>Name *</label><input type="text" name="name" id="plName" required placeholder="PL_INTERNAL_NETWORKS" class="wg-input-mono" spellcheck="false"></div>
          <div class="rt-field"><label>Description</label><input type="text" name="description" id="plDesc" placeholder="Corporate internal network prefixes"></div>
        </div>
        <div class="rt-field">
          <label>Rules</label>
          <p class="hint" style="margin:0 0 6px;">Each rule permits or denies a prefix. GE/LE optionally restrict the matched prefix length range.</p>
          <table class="sr-table" id="plTable">
            <thead><tr>
              <th style="width:80px;">Seq</th><th style="width:110px;">Action</th><th>Prefix</th><th style="width:70px;">GE</th><th style="width:70px;">LE</th><th>Description</th><th style="width:34px;"></th>
            </tr></thead>
            <tbody id="plBody"></tbody>
          </table>
          <button type="button" class="btn btn-ghost btn-sm sr-add-btn" onclick="addPlRow()">+ Add Rule</button>
        </div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('ospfPlModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="ospfPlSubmit">Save Prefix List</button>
      </div>
    </form>
  </div>
</div>

<!-- OSPF Route-Map Modal -->
<div class="rt-modal-backdrop" id="ospfRmModal">
  <div class="rt-modal-dialog" style="max-width:960px;">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="ospfRmModalTitle">Add Route Map</span>
      <button type="button" onclick="closeModal('ospfRmModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="ospfRmForm" action="<?= base_url('routing/ospf/policy/route-maps/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-grid-2">
          <div class="rt-field"><label>Name *</label><input type="text" name="name" id="rmName" required placeholder="RM_INTERNAL_STATIC" class="wg-input-mono" spellcheck="false"></div>
          <div class="rt-field"><label>Description</label><input type="text" name="description" id="rmDesc" placeholder="Permit internal prefixes into OSPF"></div>
        </div>
        <div class="rt-field">
          <label>Rules</label>
          <p class="hint" style="margin:0 0 6px;">Rules are evaluated in sequence. Match on a prefix-list and optionally set the OSPF metric, metric type or tag.</p>
          <table class="sr-table" id="rmTable">
            <thead><tr>
              <th style="width:70px;">Seq</th><th style="width:100px;">Action</th><th style="width:190px;">Match Prefix-List</th><th style="width:90px;">Set Metric</th><th style="width:130px;">Set Metric-Type</th><th style="width:80px;">Set Tag</th><th>Description</th><th style="width:34px;"></th>
            </tr></thead>
            <tbody id="rmBody"></tbody>
          </table>
          <button type="button" class="btn btn-ghost btn-sm sr-add-btn" onclick="addRmRow()">+ Add Rule</button>
        </div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('ospfRmModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="ospfRmSubmit">Save Route Map</button>
      </div>
    </form>
  </div>
</div>

<!-- OSPF Distribute-List Modal -->
<div class="rt-modal-backdrop" id="ospfDlModal">
  <div class="rt-modal-dialog">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="ospfDlModalTitle">Add OSPF Distribute List</span>
      <button type="button" onclick="closeModal('ospfDlModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="ospfDlForm" action="<?= base_url('routing/ospf/policy/distribute-lists/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-grid-2">
          <div class="rt-field">
            <label>Prefix List *</label>
            <select name="prefix_list" id="dlPrefixList" required>
              <?php foreach (($prefixLists ?? []) as $pl): ?>
                <option value="<?= esc($pl['name']) ?>"><?= esc($pl['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rt-field">
            <label>Direction</label>
            <select name="direction" id="dlDirection" onchange="toggleDlInterface(this.value)">
              <option value="in">in — accept from neighbors</option>
              <option value="out">out — advertise to neighbors</option>
            </select>
          </div>
        </div>
        <div class="rt-field" id="dlIfaceWrap">
          <label>Interface (optional)</label>
          <select name="interface" id="dlInterface">
            <option value="">any</option>
            <?php foreach ($interfaces as $if): ?>
              <option value="<?= esc($if['physical_device']) ?>"><?= esc($if['name']) ?> (<?= esc($if['physical_device']) ?>)</option>
            <?php endforeach; ?>
            <option value="lo">lo (Loopback)</option>
          </select>
          <p class="hint">Inbound only. “any” applies the list to all interfaces.</p>
        </div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" id="dlDesc" placeholder="Filter routes from upstream"></div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('ospfDlModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="ospfDlSubmit">Save Distribute List</button>
      </div>
    </form>
  </div>
</div>

<!-- OSPF Area Filter-List Modal -->
<div class="rt-modal-backdrop" id="ospfAfModal">
  <div class="rt-modal-dialog">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="ospfAfModalTitle">Add Area Filter List</span>
      <button type="button" onclick="closeModal('ospfAfModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="ospfAfForm" action="<?= base_url('routing/ospf/policy/area-filters/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-grid-2">
          <div class="rt-field">
            <label>Area *</label>
            <input type="text" name="area_id" id="afArea" list="ospfAreaList" required placeholder="0.0.0.0" class="wg-input-mono" spellcheck="false">
            <datalist id="ospfAreaList">
              <?php foreach (($ospfAreas ?? []) as $a): ?><option value="<?= esc($a['area_id']) ?>"></option><?php endforeach; ?>
            </datalist>
            <p class="hint">Dotted-decimal (0.0.0.1) or an integer.</p>
          </div>
          <div class="rt-field">
            <label>Direction</label>
            <select name="direction" id="afDirection">
              <option value="in">in — into this area</option>
              <option value="out">out — from this area</option>
            </select>
          </div>
        </div>
        <div class="rt-field">
          <label>Prefix List *</label>
          <select name="prefix_list" id="afPrefixList" required>
            <?php foreach (($prefixLists ?? []) as $pl): ?>
              <option value="<?= esc($pl['name']) ?>"><?= esc($pl['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" id="afDesc" placeholder="Filter inter-area LSAs"></div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('ospfAfModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="ospfAfSubmit">Save Area Filter</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Prefix-list names available to route-map match selects ──
var RM_PREFIX_LISTS = <?= json_encode(array_values(array_column($prefixLists ?? [], 'name'))) ?>;

// ── OSPF sub-tab switching ──
function switchOspfSub(name, btn) {
  document.querySelectorAll('.ospf-sub-panel').forEach(function(p) { p.classList.remove('active'); });
  document.querySelectorAll('.ospf-sub-tab').forEach(function(t) { t.classList.remove('active'); });
  var panel = document.getElementById('ospf-sub-' + name);
  if (panel) panel.classList.add('active');
  if (btn) btn.classList.add('active');
  // Update ?tab=ospf&sub= in URL without page reload
  var url = new URL(window.location);
  url.searchParams.set('tab', 'ospf');
  url.searchParams.set('sub', name);
  history.replaceState(null, '', url);

  if (name === 'status') {
    refreshOspfStatus();
  }
}

// ── Refresh Live OSPF Status via AJAX ──
function refreshOspfStatus(btn) {
  var b = btn || document.getElementById('btnRefreshOspfStatus');
  var origHtml = b ? b.innerHTML : null;
  if (b) {
    b.disabled = true;
    b.style.opacity = '0.7';
    b.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite; margin-right:4px;"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48l2.83-2.83"/></svg> Refreshing...';
  }

  MicronScheduler.fetch('<?= base_url('routing/ospf/status') ?>', {
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      'Accept': 'application/json'
    }
  })
  .then(function(res) {
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
  })
  .then(function(data) {
    if (!data) return;
    var sections = data.sections || {};
    var secKeys = ['general', 'neighbors', 'routes', 'database', 'router_database', 'interfaces'];
    var anyRendered = false;
    var anyRendered = false;
    var anyRendered = false;
    var anyRendered = false;
    var anyRendered = false;
    secKeys.forEach(function(k) {
      var el = document.getElementById('ospf-status-' + k);
      if (el && sections[k]) {
        var raw = sections[k].output || 'No OSPF data returned.';
        el.textContent = raw;
        anyRendered = anyRendered || raw.trim().length > 0;
      }
    });
    var hint = document.getElementById('ospf-empty-hint');
    if (hint) {
      hint.style.display = anyRendered ? 'none' : '';
    }

    var res = data.resources || {};
    var cpuEl = document.getElementById('ospf-status-cpu');
    if (cpuEl && res.cpu_output) cpuEl.textContent = res.cpu_output;
    var memEl = document.getElementById('ospf-status-memory');
    if (memEl && res.memory_output) memEl.textContent = res.memory_output;
    var hint = document.getElementById('ospf-empty-hint');
    if (hint) {
      hint.style.display = anyRendered ? 'none' : '';
    }

    if (Array.isArray(data.live_neighbors)) {
      var statusBadge = document.querySelector('.ospf-sub-tab[onclick*="\'status\'"] .rt-tab-count');
      if (statusBadge) {
        statusBadge.textContent = data.live_neighbors.length;
      }
    }
  })
  .catch(function(err) {
    console.warn('Live OSPF status fetch error:', err);
  })
  .finally(function() {
    if (b && origHtml !== null) {
      b.disabled = false;
      b.style.opacity = '1';
      b.innerHTML = origHtml;
    }
  });
}

// ── Auto-activate sub-tab from ?sub= param ──
(function() {
  var sub = new URLSearchParams(window.location.search).get('sub');
  if (sub) {
    var btn = document.querySelector('.ospf-sub-tab[onclick*="\'' + sub + '\'"]');
    var p = document.getElementById('ospf-sub-' + sub);
    if (btn && p) {
      document.querySelectorAll('.ospf-sub-panel').forEach(function(el) { el.classList.remove('active'); });
      document.querySelectorAll('.ospf-sub-tab').forEach(function(el) { el.classList.remove('active'); });
      p.classList.add('active');
      btn.classList.add('active');
    }
  }
})();

// ── Auth password visibility toggle ──
function toggleAuthPw(val) {
  var row = document.getElementById('oifPwRow');
  if (row) row.style.display = (val !== 'none') ? '' : 'none';
}

// ── Summary Range rows ──
var srIdx = 0;
function addSrRow(prefix, noAdv, cost, substitute) {
  var i = srIdx++;
  var tr = document.createElement('tr');
  tr.innerHTML =
    '<td><input type="text" name="sr_prefix[]" value="' + (prefix||'') + '" placeholder="10.0.0.0/8"></td>' +
    '<td style="text-align:center;"><input type="checkbox" name="sr_no_adv[]" ' + (noAdv?'checked':'') + '></td>' +
    '<td><input type="number" name="sr_cost[]" value="' + (cost||'') + '" placeholder="auto" min="0" max="16777215"></td>' +
    '<td><input type="text" name="sr_substitute[]" value="' + (substitute||'') + '" placeholder="optional"></td>' +
    '<td><button type="button" onclick="this.closest(\'tr\').remove()" style="background:none;border:none;color:var(--color-red);cursor:pointer;font-size:1rem;">&times;</button></td>';
  document.getElementById('srBody').appendChild(tr);
}

// ── Open Edit OSPF Area modal ──
function openEditOspfArea(area) {
  document.getElementById('ospfAreaModalTitle').innerText = 'Edit OSPF Area: ' + area.area_id;
  document.getElementById('ospfAreaForm').action = '<?= base_url('routing/ospf/areas') ?>/' + encodeURIComponent(area.id) + '/update';
  document.getElementById('ospfAreaSubmit').innerText = 'Update Area';
  document.getElementById('areaId').value      = area.area_id    || '';
  document.getElementById('areaDesc').value    = area.description|| '';
  document.getElementById('areaType').value    = area.area_type  || 'normal';
  document.getElementById('areaDefCost').value = area.default_cost|| '';
  document.getElementById('areaAbr').value     = area.abr_shortcut|| 'default';
  document.getElementById('areaAuthType').value= area.auth_type  || 'none';
  document.getElementById('areaNoSum').checked = !!parseInt(area.no_summary);

  // Re-populate summary ranges
  document.getElementById('srBody').innerHTML = '';
  srIdx = 0;
  var ranges = [];
  try { ranges = JSON.parse(area.summary_ranges || '[]'); } catch(e) {}
  ranges.forEach(function(r) { addSrRow(r.prefix, r.not_advertise, r.cost, r.substitute); });

  openModal('ospfAreaModal');
}

// ── Prefix-list rule rows ──
function plRowHtml(seq, action, prefix, ge, le, desc) {
  return '<td><input type="number" name="pl_seq[]" value="' + (seq||'') + '" min="1" max="42949" style="width:100%;"></td>' +
    '<td><select name="pl_action[]" style="width:100%;"><option value="permit"' + (action==='deny'?'':' selected') + '>permit</option><option value="deny"' + (action==='deny'?' selected':'') + '>deny</option></select></td>' +
    '<td><input type="text" name="pl_prefix[]" value="' + (prefix||'') + '" placeholder="192.168.1.0/24" style="width:100%;"></td>' +
    '<td><input type="number" name="pl_ge[]" value="' + (ge==null?'':ge) + '" min="0" max="128" style="width:100%;"></td>' +
    '<td><input type="number" name="pl_le[]" value="' + (le==null?'':le) + '" min="0" max="128" style="width:100%;"></td>' +
    '<td><input type="text" name="pl_desc[]" value="' + (desc||'') + '" placeholder="optional" style="width:100%;"></td>' +
    '<td><button type="button" onclick="this.closest(\'tr\').remove()" style="background:none;border:none;color:var(--color-red);cursor:pointer;font-size:1rem;">&times;</button></td>';
}
function addPlRow(seq, action, prefix, ge, le, desc) {
  var tr = document.createElement('tr');
  tr.innerHTML = plRowHtml(seq, action||'permit', prefix, ge, le, desc);
  document.getElementById('plBody').appendChild(tr);
}
function openAddPrefixList() {
  document.getElementById('ospfPlModalTitle').innerText = 'Add Prefix List';
  document.getElementById('ospfPlForm').action = '<?= base_url('routing/ospf/policy/prefix-lists/store') ?>';
  document.getElementById('ospfPlSubmit').innerText = 'Save Prefix List';
  document.getElementById('plName').value = '';
  document.getElementById('plDesc').value = '';
  document.getElementById('plBody').innerHTML = '';
  addPlRow(10, 'permit', '', '', '', '');
  openModal('ospfPlModal');
}
function openEditPrefixList(list) {
  document.getElementById('ospfPlModalTitle').innerText = 'Edit Prefix List: ' + list.name;
  document.getElementById('ospfPlForm').action = '<?= base_url('routing/ospf/policy/prefix-lists') ?>/' + encodeURIComponent(list.id) + '/update';
  document.getElementById('ospfPlSubmit').innerText = 'Update Prefix List';
  document.getElementById('plName').value = list.name || '';
  document.getElementById('plDesc').value = list.description || '';
  document.getElementById('plBody').innerHTML = '';
  var rules = [];
  try { rules = JSON.parse(list.rules || '[]'); } catch(e) {}
  if (rules.length === 0) { addPlRow(10, 'permit', '', '', '', ''); }
  rules.forEach(function(r) { addPlRow(r.seq, r.action, r.prefix, r.ge, r.le, r.description); });
  openModal('ospfPlModal');
}

// ── Route-map rule rows ──
function rmPlOptions(selected) {
  var html = '<option value="">— none —</option>';
  RM_PREFIX_LISTS.forEach(function(n) {
    html += '<option value="' + n + '"' + (n === selected ? ' selected' : '') + '>' + n + '</option>';
  });
  return html;
}
function rmRowHtml(seq, action, match, metric, mtype, tag, desc) {
  return '<td><input type="number" name="rm_seq[]" value="' + (seq||'') + '" min="1" max="42949" style="width:100%;"></td>' +
    '<td><select name="rm_action[]" style="width:100%;"><option value="permit"' + (action==='deny'?'':' selected') + '>permit</option><option value="deny"' + (action==='deny'?' selected':'') + '>deny</option></select></td>' +
    '<td><select name="rm_match_pl[]" style="width:100%;">' + rmPlOptions(match) + '</select></td>' +
    '<td><input type="number" name="rm_set_metric[]" value="' + (metric==null?'':metric) + '" min="1" max="16777214" placeholder="optional" style="width:100%;"></td>' +
    '<td><select name="rm_set_metric_type[]" style="width:100%;"><option value=""' + (!mtype?' selected':'') + '>—</option><option value="type-1"' + (mtype==='type-1'?' selected':'') + '>Type 1 (E1)</option><option value="type-2"' + (mtype==='type-2'?' selected':'') + '>Type 2 (E2)</option></select></td>' +
    '<td><input type="number" name="rm_set_tag[]" value="' + (tag==null?'':tag) + '" min="0" max="4294967295" placeholder="optional" style="width:100%;"></td>' +
    '<td><input type="text" name="rm_desc[]" value="' + (desc||'') + '" placeholder="optional" style="width:100%;"></td>' +
    '<td><button type="button" onclick="this.closest(\'tr\').remove()" style="background:none;border:none;color:var(--color-red);cursor:pointer;font-size:1rem;">&times;</button></td>';
}
function addRmRow(seq, action, match, metric, mtype, tag, desc) {
  var tr = document.createElement('tr');
  tr.innerHTML = rmRowHtml(seq, action||'permit', match||'', metric, mtype, tag, desc);
  document.getElementById('rmBody').appendChild(tr);
}
function openAddRouteMap() {
  document.getElementById('ospfRmModalTitle').innerText = 'Add Route Map';
  document.getElementById('ospfRmForm').action = '<?= base_url('routing/ospf/policy/route-maps/store') ?>';
  document.getElementById('ospfRmSubmit').innerText = 'Save Route Map';
  document.getElementById('rmName').value = '';
  document.getElementById('rmDesc').value = '';
  document.getElementById('rmBody').innerHTML = '';
  addRmRow(10, 'permit', RM_PREFIX_LISTS[0] || '', '', '', '', '');
  openModal('ospfRmModal');
}
function openEditRouteMap(map) {
  document.getElementById('ospfRmModalTitle').innerText = 'Edit Route Map: ' + map.name;
  document.getElementById('ospfRmForm').action = '<?= base_url('routing/ospf/policy/route-maps') ?>/' + encodeURIComponent(map.id) + '/update';
  document.getElementById('ospfRmSubmit').innerText = 'Update Route Map';
  document.getElementById('rmName').value = map.name || '';
  document.getElementById('rmDesc').value = map.description || '';
  document.getElementById('rmBody').innerHTML = '';
  var rules = [];
  try { rules = JSON.parse(map.rules || '[]'); } catch(e) {}
  if (rules.length === 0) { addRmRow(10, 'permit', RM_PREFIX_LISTS[0] || '', '', '', '', ''); }
  rules.forEach(function(r) { addRmRow(r.seq, r.action, r.match_prefix_list, r.set_metric, r.set_metric_type, r.set_tag, r.description); });
  openModal('ospfRmModal');
}

// ── Distribute-list modal ──
function toggleDlInterface(dir) {
  var wrap = document.getElementById('dlIfaceWrap');
  var sel  = document.getElementById('dlInterface');
  var isOut = (dir === 'out');
  if (wrap) wrap.style.display = isOut ? 'none' : '';
  if (sel && isOut) sel.value = '';
}
function openAddDistributeList() {
  document.getElementById('ospfDlModalTitle').innerText = 'Add OSPF Distribute List';
  document.getElementById('ospfDlForm').action = '<?= base_url('routing/ospf/policy/distribute-lists/store') ?>';
  document.getElementById('ospfDlSubmit').innerText = 'Save Distribute List';
  var pl = document.getElementById('dlPrefixList');
  if (pl) pl.value = RM_PREFIX_LISTS[0] || '';
  document.getElementById('dlDirection').value = 'in';
  document.getElementById('dlInterface').value = '';
  document.getElementById('dlDesc').value = '';
  toggleDlInterface('in');
  openModal('ospfDlModal');
}
function openEditDistributeList(dl) {
  document.getElementById('ospfDlModalTitle').innerText = 'Edit OSPF Distribute List';
  document.getElementById('ospfDlForm').action = '<?= base_url('routing/ospf/policy/distribute-lists') ?>/' + encodeURIComponent(dl.id) + '/update';
  document.getElementById('ospfDlSubmit').innerText = 'Update Distribute List';
  document.getElementById('dlPrefixList').value = dl.prefix_list || '';
  document.getElementById('dlDirection').value = dl.direction || 'in';
  document.getElementById('dlInterface').value = dl.interface || '';
  document.getElementById('dlDesc').value = dl.description || '';
  toggleDlInterface(dl.direction || 'in');
  openModal('ospfDlModal');
}

// ── Area filter-list modal ──
function openAddAreaFilter() {
  document.getElementById('ospfAfModalTitle').innerText = 'Add Area Filter List';
  document.getElementById('ospfAfForm').action = '<?= base_url('routing/ospf/policy/area-filters/store') ?>';
  document.getElementById('ospfAfSubmit').innerText = 'Save Area Filter';
  document.getElementById('afArea').value = '0.0.0.0';
  document.getElementById('afDirection').value = 'in';
  var pl = document.getElementById('afPrefixList');
  if (pl) pl.value = RM_PREFIX_LISTS[0] || '';
  document.getElementById('afDesc').value = '';
  openModal('ospfAfModal');
}
function openEditAreaFilter(af) {
  document.getElementById('ospfAfModalTitle').innerText = 'Edit Area Filter List';
  document.getElementById('ospfAfForm').action = '<?= base_url('routing/ospf/policy/area-filters') ?>/' + encodeURIComponent(af.id) + '/update';
  document.getElementById('ospfAfSubmit').innerText = 'Update Area Filter';
  document.getElementById('afArea').value = af.area_id || '';
  document.getElementById('afDirection').value = af.direction || 'in';
  document.getElementById('afPrefixList').value = af.prefix_list || '';
  document.getElementById('afDesc').value = af.description || '';
  openModal('ospfAfModal');
}

// ── OSPF Interface helpers ──
function onOspfIfaceChange(val) {
  var wrap = document.getElementById('oifCustomWrap');
  var inp = document.getElementById('oifCustomInput');
  if (wrap) wrap.style.display = (val === '__custom__') ? '' : 'none';
  if (inp && val === '__custom__') inp.focus();
}

function openAddOspfIface() {
  document.getElementById('ospfIfaceModalTitle').innerText = 'Add OSPF Interface';
  document.getElementById('ospfIfaceForm').action = '<?= base_url('routing/ospf/ifaces/store') ?>';
  document.getElementById('ospfIfaceSubmit').innerText = 'Save Interface';
  var sel = document.getElementById('oifIface');
  if (sel) sel.selectedIndex = 0;
  var customWrap = document.getElementById('oifCustomWrap');
  if (customWrap) customWrap.style.display = 'none';
  var customInput = document.getElementById('oifCustomInput');
  if (customInput) customInput.value = '';
  document.getElementById('oifDesc').value = '';
  document.getElementById('oifNetType').value = 'broadcast';
  document.getElementById('oifArea').value = '';
  document.getElementById('oifMetric').value = '';
  document.getElementById('oifPassive').checked = false;
  document.getElementById('oifIgnMtu').checked = false;
  document.getElementById('oifAccept').checked = false;
  document.getElementById('oifAuthType').value = 'none';
  document.getElementById('oifAuthPw').value = '';
  document.getElementById('oifPriority').value = 1;
  document.getElementById('oifRetransmit').value = 5;
  document.getElementById('oifHello').value = 10;
  document.getElementById('oifDead').value = 40;
  document.getElementById('oifTxDelay').value = 1;
  document.getElementById('oifMinHello').value = '';
  document.getElementById('oifBfd').checked = false;
  toggleAuthPw('none');
  openModal('ospfIfaceModal');
}

// ── Open Edit OSPF Interface modal ──
function openEditOspfIface(iface) {
  document.getElementById('ospfIfaceModalTitle').innerText = 'Edit OSPF Interface: ' + iface.interface;
  document.getElementById('ospfIfaceForm').action = '<?= base_url('routing/ospf/ifaces') ?>/' + encodeURIComponent(iface.id) + '/update';
  document.getElementById('ospfIfaceSubmit').innerText = 'Update Interface';
  
  var sel = document.getElementById('oifIface');
  var customWrap = document.getElementById('oifCustomWrap');
  var customInput = document.getElementById('oifCustomInput');
  var targetIface = String(iface.interface || '').trim();
  var found = false;

  if (sel) {
    for (var i = 0; i < sel.options.length; i++) {
      if (sel.options[i].value.toLowerCase() === targetIface.toLowerCase()) {
        sel.selectedIndex = i;
        found = true;
        break;
      }
    }
    if (!found && targetIface) {
      sel.value = '__custom__';
      if (customInput) customInput.value = targetIface;
      if (customWrap) customWrap.style.display = '';
    } else {
      if (customInput) customInput.value = '';
      if (customWrap) customWrap.style.display = (sel.value === '__custom__') ? '' : 'none';
    }
  }

  document.getElementById('oifDesc').value       = iface.description     || '';
  document.getElementById('oifNetType').value    = iface.network_type    || 'broadcast';
  document.getElementById('oifArea').value       = iface.area            || '';
  document.getElementById('oifMetric').value     = iface.metric          || '';
  document.getElementById('oifPassive').checked  = !!parseInt(iface.passive);
  document.getElementById('oifIgnMtu').checked   = !!parseInt(iface.ignore_mtu);
  document.getElementById('oifAccept').checked   = !!parseInt(iface.accept_filter);
  document.getElementById('oifAuthType').value   = iface.auth_type       || 'none';
  document.getElementById('oifAuthPw').value     = iface.auth_password   || '';
  document.getElementById('oifPriority').value   = iface.router_priority || 1;
  document.getElementById('oifRetransmit').value = iface.retransmit_interval || 5;
  document.getElementById('oifHello').value      = iface.hello_interval  || 10;
  document.getElementById('oifDead').value       = iface.dead_interval   || 40;
  document.getElementById('oifTxDelay').value    = iface.transmit_delay  || 1;
  document.getElementById('oifMinHello').value   = iface.minimal_hello   || '';
  document.getElementById('oifBfd').checked      = !!parseInt(iface.bfd_enabled);
  toggleAuthPw(iface.auth_type || 'none');
  openModal('ospfIfaceModal');
}

// ── Copy the compiled FRR policy preview to the clipboard ──
function copyOspfPolicyPreview() {
  var el = document.getElementById('ospfPolicyPreview');
  if (!el) return;
  var text = el.textContent;
  var done = function () {
    var btn = document.getElementById('ospfPolicyCopyBtn');
    if (!btn) return;
    var original = btn.innerHTML;
    btn.textContent = 'Copied';
    setTimeout(function () { btn.innerHTML = original; }, 1500);
  };
  // navigator.clipboard needs a secure context; the appliance UI is often served
  // over plain HTTP, so fall back to the legacy execCommand path.
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(done).catch(function () { legacyCopy(text, done); });
  } else {
    legacyCopy(text, done);
  }
}

function legacyCopy(text, done) {
  var ta = document.createElement('textarea');
  ta.value = text;
  ta.setAttribute('readonly', '');
  ta.style.position = 'fixed';
  ta.style.top = '-1000px';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  try {
    if (document.execCommand('copy')) done();
  } catch (e) { /* clipboard blocked */ }
  document.body.removeChild(ta);
}
</script>
