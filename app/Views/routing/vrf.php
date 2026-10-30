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

.frr-form { border:1px solid var(--color-border); border-radius:var(--radius-md); overflow:hidden; margin-bottom:20px; background:var(--color-surface-1); }
.frr-section-hdr {
  background: rgba(168,85,247,0.1);
  border-bottom: 1px solid var(--color-border);
  padding: 8px 18px; font-size: 0.72rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-purple);
}
.frr-row { display: grid; grid-template-columns: 200px 1fr; border-bottom: 1px solid var(--color-border); min-height: 46px; }
.frr-row:last-child { border-bottom: none; }
.frr-lbl { padding: 11px 18px; font-size: 0.82rem; font-weight: 600; color: var(--text-secondary); border-right: 1px solid var(--color-border); display: flex; align-items: flex-start; flex-direction: column; justify-content: center; gap: 2px; background: rgba(255,255,255,0.015); }
.frr-lbl .sub { font-size: 0.68rem; font-weight: 400; color: var(--text-muted); }
.frr-val { padding: 9px 18px; display: flex; flex-direction: column; gap: 4px; }
.frr-val .hint { font-size: 0.71rem; color: var(--text-muted); line-height: 1.4; }
.frr-input { background: var(--color-surface-3); border: 1px solid var(--color-border); color: var(--text-primary); padding: 6px 9px; border-radius: var(--radius-sm); font-size: 0.85rem; max-width: 400px; width: 100%; }
.frr-input:focus { outline: none; border-color: var(--color-purple); }
.frr-input-sm { max-width: 140px; }
.frr-input-md { max-width: 260px; }
.frr-input-mono { font-family: var(--font-mono); }
.frr-select { max-width: 320px; }
.frr-chk { display: flex; align-items: center; gap: 8px; font-size: 0.83rem; color: var(--text-secondary); margin-top: 2px; }
.frr-chk input { accent-color: var(--color-purple); width: 16px; height: 16px; }
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <span>Network</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>Routing Engine</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>VRF / PBR</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:var(--tone-purple-bg); color:var(--tone-purple-fg); border:1px solid var(--tone-purple-border);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
        </svg>
      </div>
      <div>
        <h1 class="page-title">VRF &amp; Policy-Based Routing</h1>
        <p class="page-subtitle">Virtual Routing and Forwarding instances and Policy-Based Routing rules</p>
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
     VRF SECTION
     ════════════════════════════════════════════════════════════════ -->
<div style="margin-bottom:32px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; padding-bottom:12px; border-bottom:2px solid var(--color-border);">
    <div>
      <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">Virtual Routing &amp; Forwarding — separate routing tables per virtual network</h3>
      <p style="font-size:0.78rem; color:var(--text-muted); margin-top:2px;">Keeps overlapping address space from colliding and isolates tenant, customer, or management traffic</p>
    </div>
    <?php if (session()->get('is_superadmin') || in_array('routing.vrf.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('vrfModal')">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add VRF
      </button>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:0; overflow-x:auto;">
    <table class="rt-table">
      <thead><tr>
        <th>VRF Name</th>
        <th title="Linux routing table number that backs this VRF; auto assigns one in the 100-252 range">Table ID</th>
        <th title="Physical or logical interfaces assigned to this VRF; unassigned traffic stays in the default table">Interfaces</th>
        <th>Description</th>
        <th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($vrfs)): ?>
          <tr><td colspan="5" style="text-align:center; padding:28px; color:var(--text-muted);">
              <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-purple)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No VRFs defined. The default VRF is always present (table 254).</p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">VRF instances give you separate routing tables inside one device — useful for keeping tenant or customer traffic isolated, or for running multiple overlapping address spaces without conflict. Add a VRF when you need routing separation.</p>
              </div>
            </td></tr>
        <?php else: ?>
          <?php foreach ($vrfs as $v): ?>
            <?php $vifaces = is_string($v['interfaces']) ? json_decode($v['interfaces'], true) : ($v['interfaces'] ?? []); ?>
            <tr>
              <td><span class="rt-chip rt-chip-purple"><?= esc($v['name']) ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem;"><?= esc($v['table_id'] ?: 'auto') ?></td>
              <td style="font-size:0.78rem;"><?= implode(', ', array_map('esc', $vifaces ?: [])) ?: '—' ?></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($v['description'] ?: '—') ?></td>
              <td style="text-align:right;">
                <?php if (session()->get('is_superadmin') || in_array('routing.vrf.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
                  <form method="POST" action="<?= base_url('routing/vrf/' . esc($v['id']) . '/delete') ?>" style="display:inline;"
                        onsubmit="return confirm('Remove VRF <?= esc(addslashes($v['name'])) ?>? This will also remove all routes in this VRF.')"><?= csrf_field() ?>
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
     PBR SECTION
     ════════════════════════════════════════════════════════════════ -->
<div style="margin-bottom:32px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; padding-bottom:12px; border-bottom:2px solid var(--color-border);">
    <div>
      <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">Policy-Based Routing — match rules that override the normal route lookup</h3>
      <p style="font-size:0.78rem; color:var(--text-muted); margin-top:2px;">Match on source, destination, protocol, or incoming interface, then steer matching traffic to a next-hop or VRF</p>
    </div>
    <?php if (session()->get('is_superadmin') || in_array('routing.pbr.manage', session()->get('permissions') ?? [])): ?>
      <div style="display:flex; gap:8px;">
        <form method="POST" action="<?= base_url('routing/pbr/apply') ?>" style="display:inline;"><?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost btn-sm" title="Compile and push PBR rules to the dataplane">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
            Apply Policy Routing
          </button>
        </form>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('pbrModal')">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add PBR Rule
        </button>
      </div>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:0; overflow-x:auto;">
    <table class="rt-table" id="pbrRulesTable">
      <thead><tr>
        <th style="width:36px;"></th>
        <th style="width:52px;" title="Rules are evaluated lowest number first">Priority</th>
        <th title="Source address or subnet this rule matches; blank means any">Match Source</th>
        <th title="Destination address or subnet this rule matches; blank means any">Match Dest</th>
        <th title="IP protocol this rule matches; blank means any">Protocol</th>
        <th title="Only match traffic arriving on this interface">In Interface</th>
        <th title="Where matching traffic is forwarded instead of using the normal route">Set Next-Hop</th>
        <th title="Send matching traffic into a specific routing table or VRF">Set Table / VRF</th>
        <th title="Permit forwards matching traffic, Deny drops it">Action</th>
        <th style="width:70px;" title="Disabled rules stay configured but are not installed in the dataplane">Status</th>
        <th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($pbrRules)): ?>
          <tr><td colspan="11" style="text-align:center; padding:28px; color:var(--text-muted);">
              <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No PBR rules defined.</p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">PBR rules let you override the normal routing decision — match on source, destination, protocol, or interface, then send matching packets to a specific next-hop or into a VRF. Useful for sending certain traffic out a particular WAN link.</p>
              </div>
            </td></tr>
        <?php else: ?>
          <?php foreach ($pbrRules as $pb): ?>
            <tr draggable="true" data-pbr-id="<?= esc($pb['id']) ?>">
              <td class="pbr-drag" title="Drag to reorder">
                <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/></svg>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-muted); text-align:center;"><?= esc($pb['priority']) ?></td>
              <td>
                <span class="rt-chip"><?= esc($pb['match_src'] ?: 'any') ?></span>
              </td>
              <td>
                <span class="rt-chip"><?= esc($pb['match_dst'] ?: 'any') ?></span>
              </td>
              <td><span class="rt-chip"><?= esc(strtoupper($pb['match_protocol'] ?: 'any')) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($pb['match_iif'] ?: '—') ?></td>
              <td><span class="rt-chip rt-chip-blue"><?= esc($pb['set_nexthop'] ?: '—') ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);">
                <?= esc($pb['set_table'] ? "table {$pb['set_table']}" : ($pb['set_vrf'] ?: '—')) ?>
              </td>
              <td>
                <?php if ($pb['action'] === 'deny'): ?>
                  <span class="rt-chip rt-chip-red">DENY</span>
                <?php else: ?>
                  <span class="rt-chip rt-chip-green">PERMIT</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($pb['enabled'])): ?>
                  <span class="rt-chip rt-chip-green">ACTIVE</span>
                <?php else: ?>
                  <span class="rt-chip">DISABLED</span>
                <?php endif; ?>
              </td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if (session()->get('is_superadmin') || in_array('routing.pbr.manage', session()->get('permissions') ?? [])): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditPbr(<?= json_encode($pb) ?>)' title="Edit PBR rule">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/pbr/' . esc($pb['id']) . '/toggle') ?>" style="display:inline;"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px;" title="<?= !empty($pb['enabled']) ? 'Disable' : 'Enable' ?> this rule">
                      <?= !empty($pb['enabled']) ? 'Disable' : 'Enable' ?>
                    </button>
                  </form>
                  <form method="POST" action="<?= base_url('routing/pbr/' . esc($pb['id']) . '/delete') ?>" style="display:inline;"
                        onsubmit="return confirm('Remove PBR rule <?= esc(addslashes($pb['description'] ?: 'priority ' . $pb['priority'])) ?>?')"><?= csrf_field() ?>
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

<!-- VRF Modal -->
<div class="rt-modal-backdrop" id="vrfModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">Add VRF</span>
      <button type="button" onclick="closeModal('vrfModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('routing/vrf/store') ?>">
      <?= csrf_field() ?>
      <div class="rt-modal-body">
        <div class="rt-grid-2">
          <div class="rt-field"><label>VRF Name *</label><input type="text" name="name" required placeholder="MGMT"></div>
          <div class="rt-field"><label>Table ID</label><input type="number" name="table_id" placeholder="auto (100-252)" min="1" max="252"><p class="hint">Linux routing table number</p></div>
        </div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" placeholder="Management VRF"></div>
        <div class="rt-field"><label>Interfaces (comma-separated)</label><input type="text" name="interfaces" placeholder="eth2, eth3" class="wg-input-mono" spellcheck="false"><p class="hint">Physical interface names assigned to this VRF</p></div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('vrfModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Create VRF</button>
      </div>
    </form>
  </div>
</div>

<!-- PBR Modal -->
<div class="rt-modal-backdrop" id="pbrModal">
  <div class="rt-modal-dialog">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="pbrModalTitle">Add PBR Rule</span>
      <button type="button" onclick="closeModal('pbrModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="pbrForm" action="<?= base_url('routing/pbr/store') ?>">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="max-height:80vh; overflow-y:auto;">
        <p style="font-size:0.78rem; color:var(--text-muted); margin-bottom:14px;">Match criteria (leave blank = any). Set action defines where traffic is forwarded.</p>
        <div style="border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px; margin-bottom:14px;">
          <p style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:var(--color-cyan); margin-bottom:10px;">Match Criteria</p>
          <div class="rt-grid-2">
            <div class="rt-field"><label>Source IP / CIDR</label><input type="text" name="match_src" id="pbrSrc" placeholder="10.10.0.0/24" class="wg-input-mono" spellcheck="false"></div>
            <div class="rt-field"><label>Destination IP / CIDR</label><input type="text" name="match_dst" id="pbrDst" placeholder="0.0.0.0/0" class="wg-input-mono" spellcheck="false"></div>
          </div>
          <div class="rt-grid-2">
            <div class="rt-field"><label>Protocol</label>
              <select name="match_protocol" id="pbrProto">
                <option value="">Any</option>
                <option>tcp</option><option>udp</option><option>icmp</option>
              </select>
            </div>
            <div class="rt-field"><label>DSCP</label><input type="text" name="match_dscp" id="pbrDscp" placeholder="ef / 46"></div>
          </div>
          <div class="rt-field"><label>Incoming Iface</label><input type="text" name="match_iif" id="pbrIif" placeholder="eth1"></div>
        </div>
        <div style="border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px; margin-bottom:14px;">
          <p style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:var(--color-amber); margin-bottom:10px;">Set Action</p>
          <div class="rt-grid-3">
            <div class="rt-field"><label>Next-Hop IP</label><input type="text" name="set_nexthop" id="pbrNH" placeholder="203.0.113.1" class="wg-input-mono" spellcheck="false"></div>
            <div class="rt-field"><label>Routing Table</label><input type="number" name="set_table" id="pbrTable" placeholder="100" min="1" max="252"></div>
            <div class="rt-field"><label>VRF Name</label>
              <select name="set_vrf" id="pbrVrf">
                <option value="">— none —</option>
                <?php foreach ($vrfs as $v): ?>
                  <option value="<?= esc($v['name']) ?>"><?= esc($v['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field"><label>Priority</label><input type="number" name="priority" id="pbrPriority" value="100" min="1" max="32766"><p class="hint">Lower number = higher priority</p></div>
          <div class="rt-field"><label>Action</label>
            <select name="action" id="pbrAction">
              <option value="permit">Permit (forward)</option>
              <option value="deny">Deny (drop)</option>
            </select>
          </div>
        </div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" id="pbrDesc" placeholder="Route IoT traffic via WAN2"></div>
        <div class="rt-field" style="margin-top:10px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; text-transform:none;">
            <input type="checkbox" name="enabled" id="pbrEnabled" value="1" checked>
            Rule is enabled
          </label>
        </div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('pbrModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="pbrSubmit">Add Rule</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditPbr(r) {
  document.getElementById('pbrModalTitle').innerText = 'Edit PBR Rule #' + (r.priority || '');
  document.getElementById('pbrForm').action = '<?= base_url('routing/pbr') ?>/' + encodeURIComponent(r.id) + '/update';
  document.getElementById('pbrSubmit').innerText = 'Update Rule';
  document.getElementById('pbrPriority').value = r.priority || 100;
  document.getElementById('pbrSrc').value      = r.match_src || '';
  document.getElementById('pbrDst').value      = r.match_dst || '';
  document.getElementById('pbrProto').value    = r.match_protocol || '';
  document.getElementById('pbrDscp').value     = r.match_dscp || '';
  document.getElementById('pbrIif').value      = r.match_iif || '';
  document.getElementById('pbrNH').value       = r.set_nexthop || '';
  document.getElementById('pbrTable').value    = r.set_table || '';
  document.getElementById('pbrVrf').value      = r.set_vrf || '';
  document.getElementById('pbrAction').value   = r.action || 'permit';
  document.getElementById('pbrDesc').value     = r.description || '';
  document.getElementById('pbrEnabled').checked = parseInt(r.enabled) !== 0;
  openModal('pbrModal');
}

(function() {
  var tab = new URLSearchParams(window.location.search).get('tab');
  if (tab === 'vrf') {
    console.log('VRF/PBR tab active');
  }
})();
</script>

<?= $this->endSection() ?>
