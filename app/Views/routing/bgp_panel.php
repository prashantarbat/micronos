<?php
/**
 * BGP Comprehensive Panel — included by routing/index.php & routing/bgp.php
 *
 * Variables expected:
 *   $bgpSettings, $bgpNeighbors, $routeMaps, $prefixLists, $interfaces, $frrStatus, $isMock
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
/* ── Shared FRR form layout ── */
.frr-form {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  overflow: hidden;
  margin-bottom: 20px;
  background: var(--color-surface-1);
  box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}
.frr-section-hdr {
  background: rgba(139,92,246,0.1);
  border-bottom: 1px solid var(--color-border);
  padding: 10px 18px;
  font-size: 0.74rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--color-purple);
}
.frr-row {
  display: grid;
  grid-template-columns: 240px 1fr;
  border-bottom: 1px solid var(--color-border);
  min-height: 48px;
}
.frr-row:last-child { border-bottom: none; }
.frr-lbl {
  padding: 12px 18px;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--text-primary);
  border-right: 1px solid var(--color-border);
  display: flex;
  align-items: flex-start;
  flex-direction: column;
  justify-content: center;
  gap: 3px;
  background: var(--color-surface-2);
}
.frr-lbl .sub {
  font-size: 0.68rem;
  font-weight: 500;
  color: var(--text-muted);
  display: inline-block;
}
.frr-val {
  padding: 10px 18px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 5px;
  background: var(--color-surface-1);
}
.frr-val .hint {
  font-size: 0.72rem;
  color: var(--text-muted);
  line-height: 1.4;
  margin: 2px 0 0 0;
}
.frr-input {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  color: var(--text-primary);
  padding: 7px 12px;
  border-radius: var(--radius-sm);
  font-size: 0.85rem;
  max-width: 420px;
  width: 100%;
  transition: border-color .15s, box-shadow .15s;
}
.frr-input:focus {
  outline: none;
  border-color: var(--color-purple);
  box-shadow: 0 0 0 2px rgba(139,92,246,0.18);
}
.frr-input-sm  { max-width: 140px; }
.frr-input-md  { max-width: 260px; }
.frr-input-mono { font-family: var(--font-mono, monospace); }
.frr-select { max-width: 320px; }
.frr-chk {
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 0.83rem;
  color: var(--text-secondary);
  cursor: pointer;
}
.frr-chk input {
  accent-color: var(--color-purple);
  width: 16px;
  height: 16px;
}
.frr-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  max-width: 420px;
}
@media (max-width: 768px) {
  .frr-row { grid-template-columns: 1fr; }
  .frr-lbl { border-right: none; border-bottom: 1px solid var(--color-border); padding: 9px 14px; }
}

/* BGP sub-tab bar */
.bgp-sub-tabs {
  display: flex;
  gap: 0;
  border-bottom: 2px solid var(--color-border);
  margin-bottom: 22px;
  overflow-x: auto;
}
.bgp-sub-tab {
  display: flex; align-items: center; gap: 6px;
  padding: 8px 16px;
  font-size: 0.8rem; font-weight: 600;
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
    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
    </svg>
    BGP Settings
  </button>
  <button type="button" class="bgp-sub-tab <?= $activeBgpSub === 'neighbors' ? 'active' : '' ?>"
          onclick="switchBgpSub('neighbors', this)">
    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
    </svg>
    Neighbors (<?= count($bgpNeighbors) ?>)
  </button>
  <button type="button" class="bgp-sub-tab <?= $activeBgpSub === 'status' ? 'active' : '' ?>"
          onclick="switchBgpSub('status', this)">
    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
    </svg>
    Status &amp; Routes
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
      <div class="frr-section-hdr">General BGP Options</div>

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
        <div class="frr-lbl">Local AS<span class="sub">Your ASN — required</span></div>
        <div class="frr-val">
          <input type="number" name="local_as" class="frr-input frr-input-md"
                 value="<?= esc($bs['local_as'] ?? 65001) ?>" min="1" max="4294967295" required>
          <p class="hint">The Autonomous System Number that identifies <em>this</em> router on the BGP network. Private ASNs (64512–65534) are fine for lab/internal use; public ASNs come from your RIR. Supports 2-byte and 4-byte values.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Router ID<span class="sub">Optional — defaults to auto</span></div>
        <div class="frr-val">
          <input type="text" name="router_id" class="frr-input frr-input-mono frr-input-md"
                 value="<?= esc($bs['router_id'] ?? '') ?>" spellcheck="false" placeholder="e.g. 192.168.1.1">
          <p class="hint">A stable identifier for this BGP router. If left blank, FRR picks the highest IP on any active interface (often a loopback). Set it explicitly to avoid surprise changes when interfaces flap.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Hold Time<span class="sub">seconds — dead link detection</span></div>
        <div class="frr-val">
          <input type="number" name="hold_time" class="frr-input frr-input-sm"
                 value="<?= esc($bs['hold_time'] ?? 180) ?>" min="3" max="65535">
          <p class="hint">How long a peer can go silent before BGP declares the session down. The peer will use the <em>smaller</em> of the two hold times negotiated. Lower values detect failures faster but are less tolerant of bursty links. Default: 180s.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Keepalive Interval<span class="sub">seconds — heartbeat</span></div>
        <div class="frr-val">
          <input type="number" name="keepalive_interval" class="frr-input frr-input-sm"
                 value="<?= esc($bs['keepalive_interval'] ?? 60) ?>" min="1" max="21845">
          <p class="hint">How often this router sends a BGP keepalive to a peer. A common rule of thumb is 1/3 of the hold time. Default: 60s (pairs with the 180s default hold time).</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Connect Retry<span class="sub">seconds — reconnection</span></div>
        <div class="frr-val">
          <input type="number" name="connect_retry" class="frr-input frr-input-sm"
                 value="<?= esc($bs['connect_retry'] ?? 120) ?>" min="1" max="65535">
          <p class="hint">How long BGP waits before retrying a TCP connection to a peer that failed to come up. Default: 120s.</p>
        </div>
      </div>
    </div>

    <!-- Advanced & Path Selection -->
    <div class="frr-form">
      <div class="frr-section-hdr">Advanced &amp; Best-Path Selection</div>

      <div class="frr-row">
        <div class="frr-lbl">Log Neighbor Changes</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="log_neighbor_changes" value="1" <?= !empty($bs['log_neighbor_changes']) ? 'checked' : '' ?>>
            Log neighbor state changes (up / down / reset) to syslog
          </label>
          <p class="hint">Useful for troubleshooting flapping peers — every state transition gets a log entry.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Graceful Restart</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="graceful_restart" value="1" <?= !empty($bs['graceful_restart']) ? 'checked' : '' ?>>
            Enable Graceful Restart (RFC 4724)
          </label>
          <p class="hint">Lets this router restart bgpd without its peers dropping all routes — forwarding state is preserved during the restart. Essential for hitless upgrades on production routers.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Deterministic MED</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="deterministic_med" value="1" <?= !empty($bs['deterministic_med']) ? 'checked' : '' ?>>
            Compare MED among paths from the same AS
          </label>
          <p class="hint">By default, MED is only compared between paths from the same neighbor AS. Enabling this compares MED across paths from the same AS even when they arrived via different peer ASes — affecting which path gets picked.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Always Compare MED</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="always_compare_med" value="1" <?= !empty($bs['always_compare_med']) ? 'checked' : '' ?>>
            Compare MED across paths from different ASes
          </label>
          <p class="hint">Overrides the default MED comparison scope so MED is considered even when paths come from different autonomous systems. Can change your best-path selection — test in a lab first.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Ignore AS-Path Length</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="bestpath_as_path_ignore" value="1" <?= !empty($bs['bestpath_as_path_ignore']) ? 'checked' : '' ?>>
            Skip AS-Path length in best-path calculation
          </label>
          <p class="hint">Normally BGP prefers paths with fewer AS hops. Enabling this ignores AS-Path length so other tie-breakers (like local pref or MED) decide. Use sparingly — it can pick longer, more expensive paths.</p>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">ECMP Multipath<span class="sub">equal-cost load balancing</span></div>
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
          <p class="hint">Install multiple equal-cost paths into the routing table so traffic is spread across them. 1 = no load balancing (single best path). Raise this when you have redundant links you want to use at the same time.</p>
        </div>
      </div>
    </div>

    <!-- Redistribution with Route Maps -->
    <div class="frr-form">
      <div class="frr-section-hdr">Route Redistribution — inject other sources into BGP</div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute Connected</div>
        <div class="frr-val">
          <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
            <label class="frr-chk">
              <input type="checkbox" name="redistribute_connected" value="1" <?= !empty($bs['redistribute_connected']) ? 'checked' : '' ?>>
              Redistribute local connected interface subnets into BGP
            </label>
            <div style="display:flex; align-items:center; gap:6px; margin-left:auto;">
              <span style="font-size:0.75rem; color:var(--text-muted);">Route Map:</span>
              <select name="redistribute_connected_rm" class="frr-input frr-select" style="max-width:200px; padding:4px 8px; font-size:0.78rem;">
                <option value="">— none (permit all) —</option>
                <?php foreach (($routeMaps ?? []) as $rm): ?>
                  <option value="<?= esc($rm['name']) ?>" <?= ($bs['redistribute_connected_rm'] ?? '') === $rm['name'] ? 'selected' : '' ?>><?= esc($rm['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute Static</div>
        <div class="frr-val">
          <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
            <label class="frr-chk">
              <input type="checkbox" name="redistribute_static" value="1" <?= !empty($bs['redistribute_static']) ? 'checked' : '' ?>>
              Redistribute configured static routes into BGP
            </label>
            <div style="display:flex; align-items:center; gap:6px; margin-left:auto;">
              <span style="font-size:0.75rem; color:var(--text-muted);">Route Map:</span>
              <select name="redistribute_static_rm" class="frr-input frr-select" style="max-width:200px; padding:4px 8px; font-size:0.78rem;">
                <option value="">— none (permit all) —</option>
                <?php foreach (($routeMaps ?? []) as $rm): ?>
                  <option value="<?= esc($rm['name']) ?>" <?= ($bs['redistribute_static_rm'] ?? '') === $rm['name'] ? 'selected' : '' ?>><?= esc($rm['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="frr-row">
        <div class="frr-lbl">Redistribute OSPF</div>
        <div class="frr-val">
          <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
            <label class="frr-chk">
              <input type="checkbox" name="redistribute_ospf" value="1" <?= !empty($bs['redistribute_ospf']) ? 'checked' : '' ?>>
              Redistribute internal OSPF routes into BGP
            </label>
            <div style="display:flex; align-items:center; gap:6px; margin-left:auto;">
              <span style="font-size:0.75rem; color:var(--text-muted);">Route Map:</span>
              <select name="redistribute_ospf_rm" class="frr-input frr-select" style="max-width:200px; padding:4px 8px; font-size:0.78rem;">
                <option value="">— none (permit all) —</option>
                <?php foreach (($routeMaps ?? []) as $rm): ?>
                  <option value="<?= esc($rm['name']) ?>" <?= ($bs['redistribute_ospf_rm'] ?? '') === $rm['name'] ? 'selected' : '' ?>><?= esc($rm['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Network Statements -->
    <div class="frr-form">
      <div class="frr-section-hdr">Network Statements — prefixes this router advertises</div>

      <div class="frr-row">
        <div class="frr-lbl">Advertised Networks<span class="sub">CIDR per line</span></div>
        <div class="frr-val">
          <textarea name="networks" rows="4" class="frr-input frr-input-mono"
                    placeholder="192.168.1.0/24&#10;10.0.0.0/16&#10;172.16.0.0/12"
                    style="max-width:500px;"><?= esc($advertisedNets) ?></textarea>
          <p class="hint">List the IPv4 or IPv6 prefixes this router should <em>originate</em> and advertise to its peers — one CIDR per line. These prefixes must already exist in the local RIB (connected, static, or redistributed) for BGP to advertise them. A blank line separates entries visually.</p>
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
        <th>Session Type</th>
        <th>Description</th>
        <th>Update Source</th>
        <th>BFD</th>
        <th>Next-Hop-Self</th>
        <th>Route Maps</th>
        <th>Prefix Lists</th>
        <th>State</th>
        <th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($bgpNeighbors)): ?>
          <tr><td colspan="11" style="text-align:center; padding:32px; color:var(--text-muted);">
            <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
              <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
              <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No BGP neighbors yet.</p>
              <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:380px;">A BGP neighbor is a peer router you exchange routes with. Add one to start peering — you'll need its IP and ASN.</p>
            </div>
          </td></tr>
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
                <?php if (!empty($n['route_map_in'])): ?>In: <span class="rt-chip rt-chip-blue" style="font-size:0.68rem;"><?= esc($n['route_map_in']) ?></span><br><?php endif; ?>
                <?php if (!empty($n['route_map_out'])): ?>Out: <span class="rt-chip rt-chip-blue" style="font-size:0.68rem;"><?= esc($n['route_map_out']) ?></span><?php endif; ?>
                <?php if (empty($n['route_map_in']) && empty($n['route_map_out'])): ?>—<?php endif; ?>
              </td>
              <td style="font-size:0.72rem; font-family:var(--font-mono); color:var(--text-secondary);">
                <?php if (!empty($n['prefix_list_in'])): ?>In: <span class="rt-chip rt-chip-emerald" style="font-size:0.68rem;"><?= esc($n['prefix_list_in']) ?></span><br><?php endif; ?>
                <?php if (!empty($n['prefix_list_out'])): ?>Out: <span class="rt-chip rt-chip-emerald" style="font-size:0.68rem;"><?= esc($n['prefix_list_out']) ?></span><?php endif; ?>
                <?php if (empty($n['prefix_list_in']) && empty($n['prefix_list_out'])): ?>—<?php endif; ?>
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
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditBgp(<?= htmlspecialchars(json_encode($n), ENT_QUOTES, "UTF-8") ?>)' title="Edit Neighbor">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/bgp/' . esc($n['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove BGP neighbor <?= esc(addslashes($n['neighbor_ip'])) ?>?')"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);" title="Delete Neighbor">
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
     SUB-PANEL: Status
     ================================================================ -->
<div class="bgp-sub-panel <?= $activeBgpSub === 'status' ? 'active' : '' ?>" id="bgp-sub-status">
  <div class="card" style="margin-bottom:20px; padding:20px;">
    <h3 style="font-size:0.95rem; font-weight:700; margin-bottom:12px;">BGP Operational State</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:20px;">
      <div style="background:var(--color-surface-2); padding:12px 16px; border-radius:var(--radius-md); border:1px solid var(--color-border);">
        <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">DAEMON STATUS</div>
        <div style="font-size:1.1rem; font-weight:700; color:var(--color-emerald); margin-top:4px;">
          <?= !empty($frrStatus['running']) ? 'Active / Running' : 'Stopped' ?>
        </div>
      </div>
      <div style="background:var(--color-surface-2); padding:12px 16px; border-radius:var(--radius-md); border:1px solid var(--color-border);">
        <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">LOCAL AS NUMBER</div>
        <div style="font-size:1.1rem; font-weight:700; color:var(--color-purple); margin-top:4px;">
          AS<?= esc($bs['local_as'] ?? 65001) ?>
        </div>
      </div>
      <div style="background:var(--color-surface-2); padding:12px 16px; border-radius:var(--radius-md); border:1px solid var(--color-border);">
        <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">ACTIVE PEERS</div>
        <div style="font-size:1.1rem; font-weight:700; color:var(--color-cyan); margin-top:4px;">
          <?= count($bgpNeighbors) ?> configured
        </div>
      </div>
      <div style="background:var(--color-surface-2); padding:12px 16px; border-radius:var(--radius-md); border:1px solid var(--color-border);">
        <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">ROUTER ID</div>
        <div style="font-size:1.1rem; font-weight:700; color:var(--text-primary); font-family:var(--font-mono); margin-top:4px;">
          <?= esc(($bs['router_id'] ?? '') ?: 'auto-detect') ?>
        </div>
      </div>
    </div>

    <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:10px;">
      <p style="margin:0 0 6px 0;"><strong>Reading the BGP state:</strong> <code>Idle</code> = not started, <code>Connect</code> = TCP in progress, <code>Active</code> = retrying connection, <code>OpenSent/OpenConfirm</code> = handshake in progress, <code>Established</code> = routes are being exchanged.</p>
      <p style="margin:0;">Use <strong>Diagnostics &gt; Shell</strong> or run <code>vtysh -c 'show ip bgp summary'</code> for the live peer state machine and prefix counts.</p>
    </div>
  </div>
</div>

<!-- ================================================================
     MODAL: BGP Neighbor (with Route-Maps & Prefix-Lists)
     ================================================================ -->
<div class="rt-modal-backdrop" id="bgpModal">
  <div class="rt-modal-dialog" style="max-width:640px;">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="bgpModalTitle">Add BGP Neighbor</span>
      <button type="button" onclick="closeModal('bgpModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="bgpForm" action="<?= base_url('routing/bgp/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-grid-2">
          <div class="rt-field"><label>Neighbor IP *</label><input type="text" name="neighbor_ip" id="bgpIP" required placeholder="10.0.0.1" class="wg-input-mono" spellcheck="false"><p class="hint">IPv4 or IPv6 peer address</p></div>
          <div class="rt-field"><label>Remote AS *</label><input type="number" name="remote_as" id="bgpRemoteAS" required placeholder="65002" min="1" max="4294967295"><p class="hint">Peer Autonomous System number</p></div>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field"><label>Update Source</label><input type="text" name="update_source" id="bgpUpdateSrc" placeholder="lo or 192.168.1.1"><p class="hint">Interface or IP for TCP session</p></div>
          <div class="rt-field"><label>Description</label><input type="text" name="description" id="bgpDesc" placeholder="Upstream provider"></div>
        </div>

        <!-- Route Maps Association -->
        <div class="rt-grid-2">
          <div class="rt-field">
            <label>Route Map In</label>
            <select name="route_map_in" id="bgpRmIn" style="width:100%;">
              <option value="">— none —</option>
              <?php foreach (($routeMaps ?? []) as $rm): ?>
                <option value="<?= esc($rm['name']) ?>"><?= esc($rm['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint">Filter routes received from this neighbor</p>
          </div>
          <div class="rt-field">
            <label>Route Map Out</label>
            <select name="route_map_out" id="bgpRmOut" style="width:100%;">
              <option value="">— none —</option>
              <?php foreach (($routeMaps ?? []) as $rm): ?>
                <option value="<?= esc($rm['name']) ?>"><?= esc($rm['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint">Filter routes advertised to this neighbor</p>
          </div>
        </div>

        <!-- Prefix Lists Association -->
        <div class="rt-grid-2">
          <div class="rt-field">
            <label>Prefix List In</label>
            <select name="prefix_list_in" id="bgpPlIn" style="width:100%;">
              <option value="">— none —</option>
              <?php foreach (($prefixLists ?? []) as $pl): ?>
                <option value="<?= esc($pl['name']) ?>"><?= esc($pl['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint">Prefix filter for inbound updates</p>
          </div>
          <div class="rt-field">
            <label>Prefix List Out</label>
            <select name="prefix_list_out" id="bgpPlOut" style="width:100%;">
              <option value="">— none —</option>
              <?php foreach (($prefixLists ?? []) as $pl): ?>
                <option value="<?= esc($pl['name']) ?>"><?= esc($pl['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint">Prefix filter for outbound advertisements</p>
          </div>
        </div>

        <div class="rt-field"><label>MD5 Password (optional)</label><input type="password" name="password" id="bgpPw" placeholder="BGP session password" autocomplete="new-password" spellcheck="false"></div>
        <div style="display:flex; gap:20px; margin-top:4px; flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:7px;font-size:0.82rem;color:var(--text-secondary);cursor:pointer;">
            <input type="checkbox" name="next_hop_self" id="bgpNHS" value="1"> Next-Hop-Self
          </label>
          <label style="display:flex;align-items:center;gap:7px;font-size:0.82rem;color:var(--text-secondary);cursor:pointer;">
            <input type="checkbox" name="bfd_enabled" id="bgpBfd" value="1"> Enable BFD
          </label>
          <label style="display:flex;align-items:center;gap:7px;font-size:0.82rem;color:var(--text-secondary);cursor:pointer;">
            <input type="checkbox" name="enabled" id="bgpEnabled" value="1" checked> Active / Peering Enabled
          </label>
        </div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('bgpModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="bgpSubmit">Add Neighbor</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchBgpSub(name, btn) {
  document.querySelectorAll('.bgp-sub-tab').forEach(function(t) { t.classList.remove('active'); });
  document.querySelectorAll('.bgp-sub-panel').forEach(function(p) { p.classList.remove('active'); });
  if (btn) btn.classList.add('active');
  var target = document.getElementById('bgp-sub-' + name);
  if (target) target.classList.add('active');
}

function setSelectVal(id, value) {
  var sel = document.getElementById(id);
  if (!sel) return;
  sel.value = value || '';
}

function openAddBgpModal() {
  document.getElementById('bgpModalTitle').innerText = 'Add BGP Neighbor';
  document.getElementById('bgpForm').action = '<?= base_url('routing/bgp/store') ?>';
  document.getElementById('bgpSubmit').innerText = 'Add Neighbor';
  document.getElementById('bgpIP').value        = '';
  document.getElementById('bgpRemoteAS').value  = '';
  document.getElementById('bgpUpdateSrc').value = '';
  document.getElementById('bgpDesc').value      = '';
  setSelectVal('bgpRmIn', '');
  setSelectVal('bgpRmOut', '');
  setSelectVal('bgpPlIn', '');
  setSelectVal('bgpPlOut', '');
  document.getElementById('bgpPw').value        = '';
  document.getElementById('bgpNHS').checked     = false;
  document.getElementById('bgpBfd').checked     = false;
  document.getElementById('bgpEnabled').checked = true;
  openModal('bgpModal');
}

function openEditBgp(n) {
  document.getElementById('bgpModalTitle').innerText = 'Edit BGP Neighbor: ' + n.neighbor_ip;
  document.getElementById('bgpForm').action = '<?= base_url('routing/bgp') ?>/' + encodeURIComponent(n.id) + '/update';
  document.getElementById('bgpSubmit').innerText = 'Update Neighbor';
  document.getElementById('bgpIP').value        = n.neighbor_ip  || '';
  document.getElementById('bgpRemoteAS').value  = n.remote_as    || '';
  document.getElementById('bgpUpdateSrc').value = n.update_source|| '';
  document.getElementById('bgpDesc').value      = n.description  || '';
  setSelectVal('bgpRmIn', n.route_map_in || '');
  setSelectVal('bgpRmOut', n.route_map_out || '');
  setSelectVal('bgpPlIn', n.prefix_list_in || '');
  setSelectVal('bgpPlOut', n.prefix_list_out || '');
  document.getElementById('bgpPw').value        = n.password     || '';
  document.getElementById('bgpNHS').checked     = parseInt(n.next_hop_self) === 1;
  document.getElementById('bgpBfd').checked     = parseInt(n.bfd_enabled)   === 1;
  document.getElementById('bgpEnabled').checked = parseInt(n.enabled)       !== 0;
  openModal('bgpModal');
}
</script>
