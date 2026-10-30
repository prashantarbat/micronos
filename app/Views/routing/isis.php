<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
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
.frr-badge { display: flex; align-items: center; gap: 6px; font-size: 0.78rem; color: var(--text-secondary); }
.frr-badge strong { color: var(--text-primary); }
.frr-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--color-emerald); box-shadow: 0 0 6px var(--color-emerald); }

.frr-form { border:1px solid var(--color-border); border-radius:var(--radius-md); overflow:hidden; margin-bottom:20px; background:var(--color-surface-1); }
.frr-section-hdr {
  background: rgba(239,68,68,0.1);
  border-bottom: 1px solid var(--color-border);
  padding: 8px 18px;
  font-size: 0.72rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--color-red);
}
.frr-row { display: grid; grid-template-columns: 200px 1fr; border-bottom: 1px solid var(--color-border); min-height: 46px; }
.frr-row:last-child { border-bottom: none; }
.frr-lbl { padding: 11px 18px; font-size: 0.82rem; font-weight: 600; color: var(--text-secondary); border-right: 1px solid var(--color-border); display: flex; align-items: flex-start; flex-direction: column; justify-content: center; gap: 2px; background: rgba(255,255,255,0.015); }
.frr-lbl .sub { font-size: 0.68rem; font-weight: 400; color: var(--text-muted); }
.frr-val { padding: 9px 18px; display: flex; flex-direction: column; gap: 4px; }
.frr-val .hint { font-size: 0.71rem; color: var(--text-muted); line-height: 1.4; }
.frr-input { background: var(--color-surface-3); border: 1px solid var(--color-border); color: var(--text-primary); padding: 6px 9px; border-radius: var(--radius-sm); font-size: 0.85rem; max-width: 400px; width: 100%; }
.frr-input:focus { outline: none; border-color: var(--color-red); }
.frr-input-sm { max-width: 140px; }
.frr-input-md { max-width: 260px; }
.frr-input-mono { font-family: var(--font-mono); }
.frr-select { max-width: 320px; }
.frr-chk { display: flex; align-items: center; gap: 8px; font-size: 0.83rem; color: var(--text-secondary); margin-top: 2px; }
.frr-chk input { accent-color: var(--color-red); width: 16px; height: 16px; }
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <span>Network</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>Routing Engine</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>IS-IS</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:var(--tone-red-bg); color:var(--tone-red-fg); border:1px solid var(--tone-red-border);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
      </div>
      <div>
        <h1 class="page-title">IS-IS (Intermediate System to Intermediate System)</h1>
        <p class="page-subtitle">Configure IS-IS interfaces, NET addresses, area tags, and circuit settings</p>
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
     IS-IS SETTINGS
     ════════════════════════════════════════════════════════════════ -->
<div style="margin-bottom:24px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0;">IS-IS Global Settings — process-wide identity, router type, and metric handling</h3>
    <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('isisSettingsModal')">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Edit Settings
      </button>
    <?php endif; ?>
  </div>

  <div class="frr-form">
    <div class="frr-section-hdr">IS-IS Daemon Settings (isisd)</div>
    <form method="POST" action="<?= base_url('routing/isis/settings/save') ?>">
      <?= csrf_field() ?>
      <div class="frr-row">
        <div class="frr-lbl">Enable IS-IS</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="enabled" value="1" <?= !empty($isisSettings['enabled']) ? 'checked' : '' ?>>
            Enable IS-IS routing daemon (isisd)
          </label>
        </div>
      </div>
      <div class="frr-row">
        <div class="frr-lbl">Router Type</div>
        <div class="frr-val">
          <select name="is_type" class="frr-input frr-select">
            <option value="level-1" <?= ($isisSettings['is_type'] ?? 'level-1-2') === 'level-1' ? 'selected' : '' ?>>Level-1 (station)</option>
            <option value="level-2-only" <?= ($isisSettings['is_type'] ?? 'level-1-2') === 'level-2-only' ? 'selected' : '' ?>>Level-2 (area)</option>
            <option value="level-1-2" <?= ($isisSettings['is_type'] ?? 'level-1-2') === 'level-1-2' ? 'selected' : '' ?>>Level-1-2 (both)</option>
          </select>
          <p class="hint">Level-1: intra-area only. Level-2: backbone. Level-1-2: both.</p>
        </div>
      </div>
      <div class="frr-row">
        <div class="frr-lbl">Metric Style</div>
        <div class="frr-val">
          <select name="metric_style" class="frr-input frr-select">
            <option value="narrow" <?= ($isisSettings['metric_style'] ?? 'narrow') === 'narrow' ? 'selected' : '' ?>>Narrow (legacy, 6-bit)</option>
            <option value="wide" <?= ($isisSettings['metric_style'] ?? 'narrow') === 'wide' ? 'selected' : '' ?>>Wide (32-bit, recommended)</option>
            <option value="transition" <?= ($isisSettings['metric_style'] ?? 'narrow') === 'transition' ? 'selected' : '' ?>>Transition</option>
          </select>
        </div>
      </div>
      <div class="frr-row">
        <div class="frr-lbl">Reference BW (Mbps)</div>
        <div class="frr-val">
          <input type="number" name="reference_bandwidth" class="frr-input frr-input-sm"
                 value="<?= esc($isisSettings['reference_bandwidth'] ?? 100) ?>" min="1" max="4294967">
          <p class="hint">Used to calculate interface metrics. Default: 100 Mbps.</p>
        </div>
      </div>
      <div class="frr-row">
        <div class="frr-lbl">Compatible L1/L2</div>
        <div class="frr-val">
          <label class="frr-chk">
            <input type="checkbox" name="compatible_l1_l2" value="1" <?= !empty($isisSettings['compatible_l1_l2']) ? 'checked' : '' ?>>
            Use same metric style for L1 and L2
          </label>
        </div>
      </div>
      <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
        <div style="display:flex; justify-content:flex-end; margin-top:16px;">
          <button type="submit" class="btn btn-primary">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            Save IS-IS Settings
          </button>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     IS-IS INTERFACES
     ════════════════════════════════════════════════════════════════ -->
<div style="margin-bottom:24px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0;">IS-IS Interfaces — circuits that form adjacencies with neighbouring routers</h3>
    <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openAddIsisInterface()">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Interface
      </button>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:0; overflow-x:auto;">
    <table class="rt-table">
      <thead><tr>
        <th>Interface</th>
        <th title="Level-1 stays inside an area, Level-2 forms the backbone, Level-1-2 does both">Circuit Type</th>
        <th title="Broadcast discovers neighbours by multicast; point-to-point on serial, tunnel, and P2P links">Network</th>
        <th title="Interface cost used by the SPF calculation for each level; blank means automatic from reference bandwidth">Metric L1/L2</th>
        <th title="Hello interval × multiplier sets how quickly a silent neighbour is declared down">Hello</th>
        <th title="Bidirectional Forwarding Detection tears the adjacency down faster than hellos alone">BFD</th>
        <th>Description</th>
        <th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($isisInterfaces)): ?>
          <tr><td colspan="8" style="text-align:center; padding:28px; color:var(--text-muted);">
              <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-red)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No IS-IS interfaces configured.</p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">IS-IS only runs on interfaces you explicitly enable here — adding one starts hello packets and lets the router form adjacencies on that circuit. Set the circuit type and network type to match the neighbouring router, and make sure a NET address is configured above first.</p>
              </div>
            </td></tr>
        <?php else: ?>
          <?php foreach ($isisInterfaces as $ii): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($ii['interface']) ?></span></td>
              <td><span class="badge badge-info" style="font-size:0.65rem;"><?= esc($ii['circuit_type'] ?? 'level-1-2') ?></span></td>
              <td><span class="rt-chip"><?= esc($ii['network_type'] ?? 'broadcast') ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);">
                <?= $ii['metric_l1'] !== null ? esc($ii['metric_l1']) : '—' ?> / <?= $ii['metric_l2'] !== null ? esc($ii['metric_l2']) : '—' ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.78rem;"><?= esc($ii['hello_interval'] ?? 10) ?>s &times;<?= esc($ii['hello_multiplier'] ?? 3) ?></td>
              <td><?= !empty($ii['bfd_enabled']) ? '<span class="badge badge-success" style="font-size:0.65rem;">ON</span>' : '<span style="color:var(--text-muted); font-size:0.75rem;">—</span>' ?></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($ii['description'] ?: '—') ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditIsisInterface(<?= json_encode($ii) ?>)' title="Edit IS-IS interface">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/isis/interfaces/' . esc($ii['id']) . '/delete') ?>" style="display:inline;"
                        onsubmit="return confirm('Remove IS-IS from <?= esc(addslashes($ii['interface'])) ?>?')"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);" title="Remove IS-IS interface">
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

<!-- IS-IS Settings Modal -->
<div class="rt-modal-backdrop" id="isisSettingsModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">IS-IS Settings</span>
      <button type="button" onclick="closeModal('isisSettingsModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('routing/isis/settings/save') ?>">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="max-height:60vh; overflow-y:auto;">
        <div class="frr-form">
          <div class="frr-section-hdr">IS-IS Daemon Settings</div>
          <div class="frr-row">
            <div class="frr-lbl">Enable IS-IS</div>
            <div class="frr-val">
              <label class="frr-chk"><input type="checkbox" name="enabled" value="1" <?= !empty($isisSettings['enabled']) ? 'checked' : '' ?>> Enable IS-IS</label>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Area Tag</div>
            <div class="frr-val">
              <input type="text" name="area_tag" class="frr-input frr-input-md" value="<?= esc($isisSettings['area_tag'] ?? 'DEFAULT') ?>" placeholder="DEFAULT">
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">NET Address</div>
            <div class="frr-val">
              <input type="text" name="net" class="frr-input frr-input-md frr-input-mono" value="<?= esc($isisSettings['net'] ?? '') ?>" placeholder="49.0001.1921.6800.1001.00">
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Router Type</div>
            <div class="frr-val">
              <select name="is_type" class="frr-input frr-select">
                <option value="level-1" <?= ($isisSettings['is_type'] ?? '') === 'level-1' ? 'selected' : '' ?>>Level-1</option>
                <option value="level-2-only" <?= ($isisSettings['is_type'] ?? '') === 'level-2-only' ? 'selected' : '' ?>>Level-2</option>
                <option value="level-1-2" <?= ($isisSettings['is_type'] ?? '') === 'level-1-2' ? 'selected' : '' ?>>Level-1-2</option>
              </select>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Metric Style</div>
            <div class="frr-val">
              <select name="metric_style" class="frr-input frr-select">
                <option value="narrow" <?= ($isisSettings['metric_style'] ?? '') === 'narrow' ? 'selected' : '' ?>>Narrow</option>
                <option value="wide" <?= ($isisSettings['metric_style'] ?? '') === 'wide' ? 'selected' : '' ?>>Wide</option>
                <option value="transition" <?= ($isisSettings['metric_style'] ?? '') === 'transition' ? 'selected' : '' ?>>Transition</option>
              </select>
            </div>
          </div>
          <div class="frr-row">
            <div class="frr-lbl">Reference BW (Mbps)</div>
            <div class="frr-val">
              <input type="number" name="reference_bandwidth" class="frr-input frr-input-sm" value="<?= esc($isisSettings['reference_bandwidth'] ?? 100) ?>" min="1" max="4294967">
            </div>
          </div>
        </div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('isisSettingsModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save Settings</button>
      </div>
    </form>
  </div>
</div>

<!-- IS-IS Interface Modal -->
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

(function() {
  var tab = new URLSearchParams(window.location.search).get('tab');
  if (tab === 'isis') {
    console.log('IS-IS tab active');
  }
})();
</script>

<?= $this->endSection() ?>
