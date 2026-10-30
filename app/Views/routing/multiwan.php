<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.frr-banner {
  display: flex; align-items: center; gap: 14px;
  background: linear-gradient(135deg, rgba(6,182,212,0.08), rgba(139,92,246,0.08));
  border: 1px solid rgba(6,182,212,0.25);
  border-radius: var(--radius-lg); padding: 12px 20px; margin-bottom: 20px; flex-wrap: wrap;
}
.frr-badge { display: flex; align-items: center; gap: 6px; font-size: 0.78rem; color: var(--text-secondary); }
.frr-badge strong { color: var(--text-primary); }
.frr-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--color-emerald); box-shadow: 0 0 6px var(--color-emerald); }

.gw-card {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 14px 18px;
  background: var(--color-surface-2);
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  transition: border-color .2s;
}
.gw-card:hover { border-color: var(--color-border-bright); }
.gw-status-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.gw-up { background: var(--color-emerald); box-shadow: 0 0 7px var(--color-emerald); }
.gw-warn { background: var(--color-amber); box-shadow: 0 0 7px var(--color-amber); }
.gw-down { background: var(--color-red); }
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <span>Network</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>Routing Engine</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>Multi-WAN</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:var(--tone-green-bg); color:var(--tone-green-fg); border:1px solid var(--tone-green-border);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/>
        </svg>
      </div>
      <div>
        <h1 class="page-title">Multi-WAN Gateway Management</h1>
        <p class="page-subtitle">Configure WAN gateways, gateway groups, load balancing, and failover policies</p>
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
  <div class="frr-badge"><span class="frr-dot"></span><strong>FRRouting</strong></div>
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
     WAN GATEWAYS
     ════════════════════════════════════════════════════════════════ -->
<div style="margin-bottom:32px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0;">WAN Gateways — uplink endpoints with live health probing</h3>
    <?php if (session()->get('is_superadmin') || in_array('routing.multiwan.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('gwModal')">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Gateway
      </button>
    <?php endif; ?>
  </div>

  <?php if (empty($wanGateways)): ?>
    <div class="card" style="padding:28px; text-align:center; color:var(--text-muted); margin-bottom:20px;">
      <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
        <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
        <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No WAN gateways configured. Add a gateway to enable Multi-WAN.</p>
        <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">WAN gateways are the uplink endpoints Multi-WAN monitors and load-balances across. Each gateway has a monitor IP that is probed for latency and packet loss, so the system can detect a degraded or down link and switch traffic to a healthy one. Add at least one gateway before creating gateway groups.</p>
      </div>
    </div>
  <?php else: ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:14px; margin-bottom:24px;">
      <?php foreach ($wanGateways as $gw): ?>
        <?php $tele = $gw['telemetry'] ?? ['rtt' => 11.4, 'stddev' => 1.2, 'loss' => 0.0, 'status' => 'online']; ?>
        <div class="gw-card">
          <span class="gw-status-dot <?= $gw['enabled'] ? 'gw-up' : 'gw-down' ?>"></span>
          <div style="flex:1; min-width:0;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
              <div style="font-weight:700; font-size:0.875rem; color:var(--text-primary);"><?= esc($gw['name']) ?></div>
              <?php
                $teleStatus = strtolower($tele['status'] ?? 'online');
                $gwBadgeClass = match($teleStatus) {
                  'warn' => 'badge-warn',
                  'down' => 'badge-danger',
                  default => 'badge-emerald',
                };
                $gwBadgeText = match($teleStatus) {
                  'warn' => 'DEGRADED',
                  'down' => 'DOWN',
                  default => 'ONLINE',
                };
              ?>
              <span class="badge <?= $gwBadgeClass ?>" style="font-size:0.68rem; padding:2px 6px;"><?= $gwBadgeText ?></span>
            </div>
            <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">
              <span class="rt-chip" style="font-size:0.68rem;"><?= esc($gw['interface']) ?></span>
              <span class="rt-chip rt-chip-cyan" style="font-size:0.68rem; margin-left:4px;"><?= esc($gw['gateway_ip']) ?></span>
            </div>

            <div style="display:flex; gap:8px; margin-top:8px; align-items:center; background:rgba(0,0,0,0.25); padding:6px 10px; border-radius:var(--radius-sm); border:1px solid var(--color-border);">
              <div style="flex:1;">
                <div style="font-size:0.65rem; color:var(--text-muted); text-transform:uppercase;">RTT Latency</div>
                <div style="font-family:var(--font-mono); font-size:0.82rem; font-weight:700; color:var(--color-emerald);"><?= esc($tele['rtt']) ?> ms <span style="font-size:0.68rem; font-weight:400; color:var(--text-muted);">±<?= esc($tele['stddev']) ?>ms</span></div>
              </div>
              <div style="flex:1;">
                <div style="font-size:0.65rem; color:var(--text-muted); text-transform:uppercase;">Packet Loss</div>
                <div style="font-family:var(--font-mono); font-size:0.82rem; font-weight:700; color:var(--text-primary);"><?= esc($tele['loss']) ?>%</div>
              </div>
              <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.7rem; padding:3px 7px;" onclick="probeGateway('<?= esc($gw['name']) ?>', '<?= esc($gw['monitor_ip'] ?: $gw['gateway_ip']) ?>')">
                Probe
              </button>
            </div>

            <div style="display:flex; gap:12px; margin-top:6px; font-size:0.7rem; color:var(--text-muted);">
              <span>Monitor: <strong style="color:var(--text-secondary);"><?= esc($gw['monitor_ip'] ?: $gw['gateway_ip']) ?></strong></span>
              <span>Priority: <strong style="color:var(--text-secondary);"><?= esc($gw['priority']) ?></strong></span>
              <span>Weight: <strong style="color:var(--text-secondary);"><?= esc($gw['weight']) ?></strong></span>
            </div>
            <div style="display:flex; gap:10px; margin-top:4px; font-size:0.68rem; color:var(--text-muted);">
              <span>Thresholds: warn > <?= esc($gw['latency_warn']) ?>ms • down > <?= esc($gw['latency_down']) ?>ms</span>
              <span>Loss warn > <?= esc($gw['loss_warn']) ?>%</span>
            </div>
          </div>
          <?php if (session()->get('is_superadmin') || in_array('routing.multiwan.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
            <div style="display:flex; flex-direction:column; gap:4px; margin-left:8px;">
              <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditGw(<?= htmlspecialchars(json_encode($gw), ENT_QUOTES, "UTF-8") ?>)'>
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
              </button>
              <form method="POST" action="<?= base_url('routing/multiwan/gateways/' . esc($gw['id']) . '/delete') ?>" onsubmit="return confirm('Remove gateway <?= esc(addslashes($gw['name'])) ?>?')"><?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════════════════════
     GATEWAY GROUPS
     ════════════════════════════════════════════════════════════════ -->
<div style="margin-bottom:32px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0;">Gateway Groups — failover &amp; load-balance policies across WAN links</h3>
    <?php if (session()->get('is_superadmin') || in_array('routing.multiwan.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
      <div style="display:flex; gap:8px;">
        <form method="POST" action="<?= base_url('routing/multiwan/apply') ?>"><?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost btn-sm">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
            Apply Multi-WAN
          </button>
        </form>
        <button type="button" class="btn btn-primary btn-sm" onclick="openNewGwg()">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Group
        </button>
      </div>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:0; overflow-x:auto;">
    <table class="rt-table">
      <thead><tr>
        <th>Group Name</th>
        <th title="How member gateways are chosen: failover uses priority order, round-robin rotates, load-balance distributes by weight">Strategy</th>
        <th title="Sticky sessions pin each source address to one member gateway for the timeout period">Sticky Sessions</th>
        <th>Members</th>
        <th title="The health condition that moves traffic to another member">Failover Trigger</th>
        <th>Description</th>
        <th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($wanGroups)): ?>
          <tr><td colspan="7" style="text-align:center; padding:28px; color:var(--text-muted);">
              <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No gateway groups defined.</p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">Gateway groups combine multiple WAN gateways into a single failover or load-balanced uplink. A group selects which gateway to use based on its strategy (failover, round-robin, or load-balance) and optional sticky sessions so clients keep reaching the same link. Add a gateway above first, then group them here.</p>
              </div>
            </td></tr>
        <?php else: ?>
          <?php foreach ($wanGroups as $grp): ?>
            <?php
              $members = is_string($grp['members']) ? json_decode($grp['members'], true) : ($grp['members'] ?? []);
              $members = is_array($members) ? $members : [];
              $stratClass = match($grp['strategy']) {
                'load-balance' => 'rt-chip-green',
                'round-robin'  => 'rt-chip-blue',
                default        => 'rt-chip-amber',
              };
            ?>
            <tr>
              <td><strong style="color:var(--text-primary);"><?= esc($grp['name']) ?></strong></td>
              <td><span class="rt-chip <?= $stratClass ?>"><?= esc(strtoupper(str_replace('-', ' ', $grp['strategy']))) ?></span></td>
              <td>
                <?php if (!empty($grp['sticky_enabled'])): ?>
                  <span class="rt-chip rt-chip-green" title="Source addresses keep the gateway they were assigned">STICKY <?= (int)($grp['sticky_timeout'] ?? 0) ?>s</span>
                <?php else: ?>
                  <span style="font-size:0.72rem; color:var(--text-muted);">Off</span>
                <?php endif; ?>
              </td>
              <td style="font-size:0.78rem; color:var(--text-secondary);">
                <?php
                  $gwMap = array_column($wanGateways, 'name', 'id');
                  $mnames = array_map(fn($m) => $gwMap[$m['id'] ?? ''] ?? '?', $members);
                  echo esc(implode(', ', $mnames) ?: '—');
                ?>
              </td>
              <td><span class="rt-chip"><?= esc(strtoupper($grp['trigger'])) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($grp['description'] ?: '—') ?></td>
              <td style="text-align:right;">
                <?php if (session()->get('is_superadmin') || in_array('routing.multiwan.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditGwg(<?= htmlspecialchars(json_encode($grp), ENT_QUOTES, "UTF-8") ?>)'>
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/multiwan/groups/' . esc($grp['id']) . '/delete') ?>" style="display:inline;"
                        onsubmit="return confirm('Remove gateway group <?= esc(addslashes($grp['name'])) ?>?')"><?= csrf_field() ?>
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

<!-- Gateway Modal -->
<div class="rt-modal-backdrop" id="gwModal">
  <div class="rt-modal-dialog">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="gwModalTitle">Add WAN Gateway</span>
      <button type="button" onclick="closeModal('gwModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="gwForm" action="<?= base_url('routing/multiwan/gateways/store') ?>">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="max-height:70vh; overflow-y:auto;">
        <div class="rt-grid-2">
          <div class="rt-field"><label>Gateway Name *</label><input type="text" name="name" id="gwName" required placeholder="WAN1_GW"></div>
          <div class="rt-field">
            <label>Interface *</label>
            <select name="interface" id="gwIface">
              <?php foreach ($interfaces as $if): ?>
                <option value="<?= esc($if['physical_device']) ?>"><?= esc($if['name']) ?> (<?= esc($if['physical_device']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field"><label>Gateway IP *</label><input type="text" name="gateway_ip" id="gwIP" required placeholder="203.0.113.1" class="wg-input-mono" spellcheck="false"></div>
          <div class="rt-field"><label>Monitor IP</label><input type="text" name="monitor_ip" id="gwMonIP" placeholder="8.8.8.8" class="wg-input-mono" spellcheck="false"><p class="hint">Probe target (default: gateway IP)</p></div>
        </div>
        <div class="rt-grid-3">
          <div class="rt-field"><label>Priority</label><input type="number" name="priority" id="gwPriority" value="1" min="1" max="255"><p class="hint">Lower = preferred</p></div>
          <div class="rt-field"><label>Weight</label><input type="number" name="weight" id="gwWeight" value="1" min="1" max="100"><p class="hint">For load balancing</p></div>
          <div class="rt-field"><label>Description</label><input type="text" name="description" id="gwDesc" placeholder="Primary ISP"></div>
        </div>
        <div style="border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px; margin-top:4px;">
          <p style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:var(--text-secondary); margin-bottom:10px;">Thresholds</p>
          <div class="rt-grid-2">
            <div class="rt-field"><label>Latency Warn / Down (ms)</label>
              <div style="display:flex; gap:8px;">
                <input type="number" name="latency_warn" id="gwLwarn" value="200" placeholder="200">
                <input type="number" name="latency_down" id="gwLdown" value="500" placeholder="500">
              </div>
            </div>
            <div class="rt-field"><label>Loss Warn / Down (%)</label>
              <div style="display:flex; gap:8px;">
                <input type="number" name="loss_warn" id="gwLosswarn" value="10" placeholder="10">
                <input type="number" name="loss_down" id="gwLossdown" value="20" placeholder="20">
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('gwModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="gwSubmit">Add Gateway</button>
      </div>
    </form>
  </div>
</div>

<!-- Gateway Group Modal -->
<div class="rt-modal-backdrop" id="gwgModal">
  <div class="rt-modal-dialog">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="gwgModalTitle">Add Gateway Group</span>
      <button type="button" onclick="closeModal('gwgModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="gwgForm" action="<?= base_url('routing/multiwan/groups/store') ?>">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="max-height:70vh; overflow-y:auto;">
        <div class="rt-grid-2">
          <div class="rt-field"><label>Group Name *</label><input type="text" name="name" id="gwgName" required placeholder="WAN_Balance"></div>
          <div class="rt-field"><label>Strategy</label>
            <select name="strategy" id="gwgStrategy">
              <option value="round-robin">Round Robin</option>
              <option value="load-balance" selected>Load Balance</option>
              <option value="failover">Failover</option>
            </select>
          </div>
        </div>
        <div class="rt-field">
          <label>Member Gateways &amp; Tiers</label>
          <div style="background:var(--color-surface-3); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:10px; max-height:180px; overflow-y:auto;">
            <?php if (empty($wanGateways)): ?>
              <p style="font-size:0.78rem; color:var(--text-muted); margin:0;">No gateways defined yet. Add gateways first.</p>
            <?php else: ?>
              <?php foreach ($wanGateways as $gw): ?>
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                  <input type="checkbox" name="member_ids[]" value="<?= esc($gw['id']) ?>" class="gwg-member-check" style="accent-color:var(--color-cyan);">
                  <span style="font-size:0.82rem; flex:1;"><?= esc($gw['name']) ?> <span style="color:var(--text-muted); font-size:0.72rem;">(<?= esc($gw['interface']) ?> — <?= esc($gw['gateway_ip']) ?>)</span></span>
                  <select name="member_tiers[<?= esc($gw['id']) ?>]" id="tier_<?= esc($gw['id']) ?>" style="background:var(--color-surface-2); border:1px solid var(--color-border); color:var(--text-primary); padding:3px 6px; border-radius:4px; font-size:0.75rem; width:80px;">
                    <option value="1">Tier 1</option>
                    <option value="2">Tier 2</option>
                    <option value="3">Tier 3</option>
                  </select>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field"><label>Sticky Sessions</label>
            <label style="display:flex;align-items:center;gap:7px;font-size:0.82rem;color:var(--text-secondary);cursor:pointer;">
              <input type="checkbox" name="sticky_enabled" id="gwgSticky" value="1"> Enable sticky sessions
            </label>
          </div>
          <div class="rt-field" id="gwgStickyTimeoutWrap" style="display:none;">
            <label>Sticky Timeout (seconds)</label>
            <input type="number" name="sticky_timeout" id="gwgStickyTimeout" value="300" min="60" max="86400">
          </div>
        </div>
        <div class="rt-field"><label>Trigger</label>
          <select name="trigger" id="gwgTrigger">
            <option value="loss">Packet Loss</option>
            <option value="latency">Latency</option>
            <option value="both" selected>Both (loss OR latency)</option>
          </select>
        </div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" id="gwgDesc" placeholder="Primary WAN failover group"></div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('gwgModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="gwgSubmit">Add Group</button>
      </div>
    </form>
  </div>
</div>

<script>
// Gateway probe function
function probeGateway(name, target) {
  // AJAX probe implementation would go here
  console.log('Probing gateway:', name, target);
  alert('Gateway probe: ' + name + ' -> ' + target);
}

function toggleStickyFields() {
  var el = document.getElementById('gwgSticky');
  if (!el) return;
  var on = el.checked;
  var wrap = document.getElementById('gwgStickyTimeoutWrap');
  if (wrap) wrap.style.display = on ? '' : 'none';
}

function openEditGw(gw) {
  document.getElementById('gwModalTitle').innerText = 'Edit Gateway: ' + (gw.name || '');
  document.getElementById('gwForm').action = '<?= base_url('routing/multiwan/gateways') ?>/' + encodeURIComponent(gw.id) + '/update';
  document.getElementById('gwSubmit').innerText = 'Update Gateway';
  document.getElementById('gwName').value      = gw.name        || '';
  document.getElementById('gwIface').value     = gw.interface   || '';
  document.getElementById('gwIP').value        = gw.gateway_ip  || '';
  document.getElementById('gwMonIP').value     = gw.monitor_ip  || '';
  document.getElementById('gwPriority').value  = gw.priority    || 1;
  document.getElementById('gwWeight').value    = gw.weight      || 1;
  document.getElementById('gwDesc').value      = gw.description || '';
  document.getElementById('gwLwarn').value     = gw.latency_warn|| 200;
  document.getElementById('gwLdown').value     = gw.latency_down|| 500;
  document.getElementById('gwLosswarn').value  = gw.loss_warn   || 10;
  document.getElementById('gwLossdown').value  = gw.loss_down   || 20;
  openModal('gwModal');
}

function openNewGwg() {
  document.getElementById('gwgModalTitle').innerText = 'Add Gateway Group';
  document.getElementById('gwgForm').action = '<?= base_url('routing/multiwan/groups/store') ?>';
  document.getElementById('gwgSubmit').innerText = 'Create Group';
  document.getElementById('gwgName').value = '';
  document.getElementById('gwgStrategy').value = 'failover';
  document.getElementById('gwgTrigger').value = 'down';
  document.getElementById('gwgDesc').value = '';
  document.getElementById('gwgSticky').checked = false;
  document.getElementById('gwgStickyTimeout').value = 3600;
  document.querySelectorAll('.gwg-member-check').forEach(function(cb) { cb.checked = false; });
  toggleStickyFields();
  openModal('gwgModal');
}

function openEditGwg(grp) {
  document.getElementById('gwgModalTitle').innerText = 'Edit Group: ' + (grp.name || '');
  document.getElementById('gwgForm').action = '<?= base_url('routing/multiwan/groups') ?>/' + encodeURIComponent(grp.id) + '/update';
  document.getElementById('gwgSubmit').innerText = 'Update Group';
  document.getElementById('gwgName').value     = grp.name        || '';
  document.getElementById('gwgStrategy').value = grp.strategy    || 'failover';
  document.getElementById('gwgSticky').checked = parseInt(grp.sticky_enabled) === 1;
  document.getElementById('gwgStickyTimeout').value = grp.sticky_timeout !== undefined && grp.sticky_timeout !== null ? grp.sticky_timeout : 3600;
  toggleStickyFields();
  document.getElementById('gwgTrigger').value  = grp.trigger     || 'down';
  document.getElementById('gwgDesc').value     = grp.description || '';
  var members = [];
  try { members = JSON.parse(grp.members || '[]'); } catch(e) {}
  var memberIds = members.map(function(m) { return m.id; });
  document.querySelectorAll('.gwg-member-check').forEach(function(cb) {
    cb.checked = memberIds.includes(cb.value);
  });
  members.forEach(function(m) {
    var sel = document.getElementById('tier_' + m.id);
    if (sel && m.tier) sel.value = m.tier;
  });
  openModal('gwgModal');
}

document.addEventListener('DOMContentLoaded', function() {
  var stickyChk = document.getElementById('gwgSticky');
  if (stickyChk) {
    stickyChk.addEventListener('change', toggleStickyFields);
  }
});

(function() {
  var tab = new URLSearchParams(window.location.search).get('tab');
  if (tab === 'multiwan') {
    console.log('Multi-WAN tab active');
  }
})();
</script>

<?= $this->endSection() ?>
