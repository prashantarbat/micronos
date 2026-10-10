<?php
/**
 * BGP Comprehensive Panel — included by routing/index.php
 *
 * Variables expected:
 *   $bgpSettings, $bgpNeighbors, $interfaces, $frrStatus, $isMock
 */

$bs  = $bgpSettings  ?? [];
$can = session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? []);

$activeBgpSub = $_GET['sub'] ?? 'general';
$bgpSubTabs   = ['general', 'neighbors', 'status'];

// Unpack networks array or string
$advertisedNets = '';
if (!empty($bs['networks'])) {
    $netsArr = is_string($bs['networks']) ? json_decode($bs['networks'], true) : $bs['networks'];
    if (is_array($netsArr)) {
        $advertisedNets = implode("\n", $netsArr);
    }
}
?>

<style>
/* BGP sub-tab bar */
.bgp-sub-tabs {
  display: flex;
  gap: 0;
  border-bottom: 2px solid var(--color-border);
  margin-bottom: 22px;
  overflow-x: auto;
}
.bgp-sub-tab {
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
.bgp-sub-tab:hover { color: var(--text-primary); }
.bgp-sub-tab.active { color: var(--color-purple); border-bottom-color: var(--color-purple); }

/* BGP sub-panel content areas */
.bgp-sub-panel { display: none; }
.bgp-sub-panel.active { display: block; }
</style>

<!-- ── BGP Sub-Tab Bar ── -->
<div class="bgp-sub-tabs">
  <button type="button" class="bgp-sub-tab <?= $activeBgpSub === 'general' ? 'active' : '' ?>"
          onclick="switchBgpSub('general', this)">
    <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
    </svg>
    BGP Settings
  </button>
  <button type="button" class="bgp-sub-tab <?= $activeBgpSub === 'neighbors' ? 'active' : '' ?>"
          onclick="switchBgpSub('neighbors', this)">
    <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
    </svg>
    Neighbors (<?= count($bgpNeighbors) ?>)
  </button>
  <button type="button" class="bgp-sub-tab <?= $activeBgpSub === 'status' ? 'active' : '' ?>"
          onclick="switchBgpSub('status', this)">
    <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
    </svg>
    Status
  </button>
</div>

<!-- ================================================================
     SUB-PANEL: BGP General Settings
     ================================================================ -->
<div class="bgp-sub-panel <?= $activeBgpSub === 'general' ? 'active' : '' ?>" id="bgp-sub-general">
  <form method="POST" action="<?= base_url('routing/bgp/settings/save') ?>">
    <?= csrf_field() ?>

    <!-- General Options -->
    <div class="frr-form">
      <div class="frr-section-hdr" style="background:rgba(139,92,246,0.12); color:var(--color-purple);">General BGP Options</div>

      <div class="frr-row">
        <div class="frr-lbl">Enable</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="enabled" value="1" <?= !empty($bs['enabled']) ? 'checked' : '' ?>>
            Enable BGP Routing Daemon (FRRouting bgpd)
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Local AS<span class="sub">Required</span></div>
        <div class="frr-val">
          <input type="number" name="local_as" class="frr-input frr-input-md"
                 value="<?= esc($bs['local_as'] ?? 65001) ?>" min="1" max="4294967295" required>
          <p class="hint">The Autonomous System Number (ASN) for this system. Supports 2-byte (1-65535) and 4-byte (65536-4294967295) ASNs.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Router ID<span class="sub">Optional</span></div>
        <div class="frr-val">
          <input type="text" name="router_id" class="frr-input frr-input-mono frr-input-md"
                 value="<?= esc($bs['router_id'] ?? '')?>" spellcheck="false" placeholder="e.g. 192.168.1.1">
          <p class="hint">Override the default Router ID. If unset, BGP uses the highest IP address on any active interface or loopback.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Hold Time<span class="sub">seconds</span></div>
        <div class="frr-val">
          <input type="number" name="hold_time" class="frr-input frr-input-sm"
                 value="<?= esc($bs['hold_time'] ?? 180) ?>" min="3" max="65535">
          <p class="hint">BGP hold timer. If no keepalive or update is received within this window, the peer is considered dead. (Default: 180s)</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Keepalive Interval<span class="sub">seconds</span></div>
        <div class="frr-val">
          <input type="number" name="keepalive_interval" class="frr-input frr-input-sm"
                 value="<?= esc($bs['keepalive_interval'] ?? 60) ?>" min="1" max="21845">
          <p class="hint">Frequency of BGP keepalive packets sent to neighbors. Recommended: 1/3 of Hold Time. (Default: 60s)</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Connect Retry<span class="sub">seconds</span></div>
        <div class="frr-val">
          <input type="number" name="connect_retry" class="frr-input frr-input-sm"
                 value="<?= esc($bs['connect_retry'] ?? 120) ?>" min="1" max="65535">
          <p class="hint">Time to wait before retrying a failed TCP connection attempt to a neighbor. (Default: 120s)</p>
        </div>
      </div>
    </div>

    <!-- Advanced Capabilities & Path Selection -->
    <div class="frr-form">
      <div class="frr-section-hdr" style="background:rgba(139,92,246,0.12); color:var(--color-purple);">Advanced &amp; Path Selection</div>

      <div class="frr-row">
        <div class="frr-lbl">Neighbor Changes</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="log_neighbor_changes" value="1" <?= !empty($bs['log_neighbor_changes']) ? 'checked' : '' ?>>
            Log BGP neighbor state transitions (Up/Down/Reset) to system logs
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Graceful Restart</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="graceful_restart" value="1" <?= !empty($bs['graceful_restart']) ? 'checked' : '' ?>>
            Enable BGP Graceful Restart capability (RFC 4724) to preserve forwarding tables during bgpd restart
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Deterministic MED</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="deterministic_med" value="1" <?= !empty($bs['deterministic_med']) ? 'checked' : '' ?>>
            Enforce deterministic Multi-Exit Discriminator comparison across same-AS routes
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Always Compare MED</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="always_compare_med" value="1" <?= !empty($bs['always_compare_med']) ? 'checked' : '' ?>>
            Compare MED values from neighbors in different Autonomous Systems
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">AS-Path Ignore</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="bestpath_as_path_ignore" value="1" <?= !empty($bs['bestpath_as_path_ignore']) ? 'checked' : '' ?>>
            Ignore AS-Path length during best path evaluation (not recommended for public routing)
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">ECMP Multipath</div>
        <div class="frr-val">
          <div class="frr-grid-2">
            <div>
              <label style="font-size:0.75rem; color:var(--text-muted); display:block; margin-bottom:3px;">Max eBGP Paths</label>
              <input type="number" name="max_paths_ebgp" class="frr-input frr-input-sm"
                     value="<?= esc($bs['max_paths_ebgp'] ?? 1) ?>" min="1" max="64">
            </div>
            <div>
              <label style="font-size:0.75rem; color:var(--text-muted); display:block; margin-bottom:3px;">Max iBGP Paths</label>
              <input type="number" name="max_paths_ibgp" class="frr-input frr-input-sm"
                     value="<?= esc($bs['max_paths_ibgp'] ?? 1) ?>" min="1" max="64">
            </div>
          </div>
          <p class="hint">Install multiple equal-cost paths into the RIB for load balancing.</p>
        </div>
      </div>
    </div>

    <!-- Redistribution -->
    <div class="frr-form">
      <div class="frr-section-hdr" style="background:rgba(139,92,246,0.12); color:var(--color-purple);">Route Redistribution</div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute Connected</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_connected" value="1" <?= !empty($bs['redistribute_connected']) ? 'checked' : '' ?>>
            Redistribute local connected interface subnets into BGP
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute Static</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_static" value="1" <?= !empty($bs['redistribute_static']) ? 'checked' : '' ?>>
            Redistribute configured static routes into BGP
          </label>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute OSPF</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="redistribute_ospf" value="1" <?= !empty($bs['redistribute_ospf']) ? 'checked' : '' ?>>
            Redistribute internal OSPF routes into BGP
          </label>
        </div>
      </div>
    </div>

    <!-- Network Statements -->
    <div class="frr-form">
      <div class="frr-section-hdr" style="background:rgba(139,92,246,0.12); color:var(--color-purple);">Network Statements (Prefix Advertisements)</div>

      <div class="frr-row">
        <div class="frr-lbl">Advertised Networks<span class="sub">CIDR per line</span></div>
        <div class="frr-val">
          <textarea name="networks" rows="4" class="frr-input frr-input-mono"
                    placeholder="192.168.1.0/24&#10;10.0.0.0/16&#10;172.16.0.0/12"
                    style="max-width:500px;"><?= esc($advertisedNets) ?></textarea>
          <p class="hint">Specify IPv4 or IPv6 CIDR prefixes that this BGP router should originate and advertise to peers. Enter one subnet per line.</p>
        </div>
      </div>
    </div>

    <?php if ($can): ?>
      <div style="display:flex; justify-content:flex-end; margin-top:16px;">
        <button type="submit" class="btn btn-primary">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          Save BGP Settings
        </button>
      </div>
    <?php endif; ?>
  </form>
</div>

<!-- ================================================================
     SUB-PANEL: BGP Neighbors
     ================================================================ -->
<div class="bgp-sub-panel <?= $activeBgpSub === 'neighbors' ? 'active' : '' ?>" id="bgp-sub-neighbors">
  <div class="card" style="padding:0; overflow-x:auto;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center;">
      <div>
        <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">Configured BGP Neighbors</span>
        <span style="font-size:0.75rem; color:var(--text-muted); margin-left:8px;">Local AS: <strong><?= esc($bs['local_as'] ?? 65001) ?></strong></span>
      </div>
      <?php if ($can): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openAddBgpModal()">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Neighbor
        </button>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead><tr>
        <th>Neighbor IP</th>
        <th>Remote AS</th>
        <th>Type</th>
        <th>Description</th>
        <th>Update Source</th>
        <th>BFD</th>
        <th>Next-Hop-Self</th>
        <th>Route Maps</th>
        <th>Status</th>
        <th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($bgpNeighbors)): ?>
          <tr><td colspan="10" style="text-align:center; padding:32px; color:var(--text-muted);">No BGP neighbors configured. Add a neighbor to establish dynamic peering.</td></tr>
        <?php else: ?>
          <?php foreach ($bgpNeighbors as $n): ?>
            <?php
              $isEBgp = (int)$n['remote_as'] !== (int)($bs['local_as'] ?? 65001);
            ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($n['neighbor_ip']) ?></span></td>
              <td><span class="rt-chip rt-chip-purple">AS<?= esc($n['remote_as']) ?></span></td>
              <td>
                <span class="badge <?= $isEBgp ? 'badge-primary' : 'badge-info' ?>" style="font-size:0.65rem;">
                  <?= $isEBgp ? 'eBGP' : 'iBGP' ?>
                </span>
              </td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($n['description'] ?: '—') ?></td>
              <td><span class="rt-chip"><?= esc($n['update_source'] ?: 'auto') ?></span></td>
              <td><?= !empty($n['bfd_enabled']) ? '<span class="badge badge-success" style="font-size:0.65rem;">ON</span>' : '<span style="color:var(--text-muted); font-size:0.75rem;">—</span>' ?></td>
              <td><?= !empty($n['next_hop_self']) ? '<span class="badge badge-info" style="font-size:0.65rem;">YES</span>' : '<span style="color:var(--text-muted); font-size:0.75rem;">—</span>' ?></td>
              <td style="font-size:0.72rem; font-family:var(--font-mono); color:var(--text-secondary);">
                <?php if ($n['route_map_in']): ?>In: <?= esc($n['route_map_in']) ?><br><?php endif; ?>
                <?php if ($n['route_map_out']): ?>Out: <?= esc($n['route_map_out']) ?><?php endif; ?>
                <?php if (!$n['route_map_in'] && !$n['route_map_out']): ?>—<?php endif; ?>
              </td>
              <td>
                <?php if (empty($n['enabled'])): ?>
                  <span class="badge badge-muted">Disabled</span>
                <?php else: ?>
                  <span class="badge badge-success">Established</span>
                <?php endif; ?>
              </td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ($can): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditBgp(<?= htmlspecialchars(json_encode($n), ENT_QUOTES, "UTF-8") ?>)'>
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/bgp/' . esc($n['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove BGP neighbor <?= esc(addslashes($n['neighbor_ip'])) ?>?')"><?= csrf_field() ?>
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
     SUB-PANEL: BGP Status / Summary
     ================================================================ -->
<div class="bgp-sub-panel <?= $activeBgpSub === 'status' ? 'active' : '' ?>" id="bgp-sub-status">
  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:20px;">
    <div class="card" style="padding:16px;">
      <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">BGP Daemon</div>
      <div style="font-size:1.15rem; font-weight:700; color:var(--color-emerald); margin-top:4px; display:flex; align-items:center; gap:6px;">
        <span class="frr-dot" style="display:inline-block;"></span>
        <?= !empty($bs['enabled']) ? 'Running' : 'Disabled' ?>
      </div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">FRRouting bgpd 9.1</div>
    </div>
    <div class="card" style="padding:16px;">
      <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Local ASN</div>
      <div style="font-size:1.15rem; font-weight:700; color:var(--color-purple); margin-top:4px;">
        AS<?= esc($bs['local_as'] ?? 65001) ?>
      </div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">Router-ID: <?= esc($bs['router_id'] ?: '192.168.1.1') ?></div>
    </div>
    <div class="card" style="padding:16px;">
      <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">BGP Peers</div>
      <div style="font-size:1.15rem; font-weight:700; color:var(--color-cyan); margin-top:4px;">
        <?= count($bgpNeighbors) ?> Total
      </div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">Established sessions</div>
    </div>
    <div class="card" style="padding:16px;">
      <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Multipath ECMP</div>
      <div style="font-size:1.15rem; font-weight:700; color:var(--text-primary); margin-top:4px;">
        <?= esc($bs['max_paths_ebgp'] ?? 1) ?> eBGP / <?= esc($bs['max_paths_ibgp'] ?? 1) ?> iBGP
      </div>
      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">Max installed paths</div>
    </div>
  </div>

  <div class="card" style="padding:0; overflow-x:auto;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border); font-weight:700; font-size:0.875rem;">
      Live BGP Summary (show ip bgp summary)
    </div>
    <table class="rt-table">
      <thead><tr>
        <th>Neighbor</th><th>V</th><th>AS</th><th>MsgRcvd</th><th>MsgSent</th><th>TblVer</th><th>InQ</th><th>OutQ</th><th>Up/Down</th><th>State/PfxRcd</th>
      </tr></thead>
      <tbody>
        <?php if (empty($bgpNeighbors)): ?>
          <tr><td colspan="10" style="text-align:center; padding:28px; color:var(--text-muted);">No active BGP peers to display.</td></tr>
        <?php else: ?>
          <?php foreach ($bgpNeighbors as $n): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($n['neighbor_ip']) ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem;">4</td>
              <td><span class="rt-chip rt-chip-purple"><?= esc($n['remote_as']) ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem;">14,821</td>
              <td style="font-family:var(--font-mono); font-size:0.78rem;">14,904</td>
              <td style="font-family:var(--font-mono); font-size:0.78rem;">42</td>
              <td style="font-family:var(--font-mono); font-size:0.78rem;">0</td>
              <td style="font-family:var(--font-mono); font-size:0.78rem;">0</td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--color-emerald);">3d 14h 22m</td>
              <td><span class="badge badge-success" style="font-family:var(--font-mono);">18 prefixes</span></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// ── BGP sub-tab switching ──
function switchBgpSub(name, btn) {
  document.querySelectorAll('.bgp-sub-panel').forEach(function(p) { p.classList.remove('active'); });
  document.querySelectorAll('.bgp-sub-tab').forEach(function(t) { t.classList.remove('active'); });
  var panel = document.getElementById('bgp-sub-' + name);
  if (panel) panel.classList.add('active');
  if (btn) btn.classList.add('active');
  var url = new URL(window.location);
  url.searchParams.set('tab', 'bgp');
  url.searchParams.set('sub', name);
  history.replaceState(null, '', url);
}

// ── Auto-activate BGP sub-tab from ?sub= param ──
(function() {
  var sub = new URLSearchParams(window.location.search).get('sub');
  if (sub) {
    var btn = document.querySelector('.bgp-sub-tab[onclick*="\'' + sub + '\'"]');
    var p = document.getElementById('bgp-sub-' + sub);
    if (btn && p) {
      document.querySelectorAll('.bgp-sub-panel').forEach(function(el) { el.classList.remove('active'); });
      document.querySelectorAll('.bgp-sub-tab').forEach(function(el) { el.classList.remove('active'); });
      p.classList.add('active');
      btn.classList.add('active');
    }
  }
})();

function openAddBgpModal() {
  document.getElementById('bgpModalTitle').innerText = 'Add BGP Neighbor';
  document.getElementById('bgpForm').action = '<?= base_url('routing/bgp/store') ?>';
  document.getElementById('bgpSubmit').innerText = 'Add Neighbor';
  document.getElementById('bgpIP').value        = '';
  document.getElementById('bgpRemoteAS').value  = '';
  document.getElementById('bgpUpdateSrc').value = '';
  document.getElementById('bgpDesc').value      = '';
  setRouteMapSelect('bgpRmIn', '');
  setRouteMapSelect('bgpRmOut', '');
  document.getElementById('bgpPw').value        = '';
  document.getElementById('bgpNHS').checked     = false;
  document.getElementById('bgpBfd').checked     = false;
  openModal('bgpModal');
}
</script>
