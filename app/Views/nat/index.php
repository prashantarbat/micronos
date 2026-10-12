<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?= $this->include('firewall/_obj_tooltip') ?>

<?php
// Detail tooltip lookups for referenced interfaces & schedules.
$__ifaceMap = [];
foreach (($interfaces ?? []) as $__i) {
    foreach ([$__i['id'] ?? '', $__i['name'] ?? '', $__i['physical_device'] ?? ''] as $__k) {
        if ($__k !== '') { $__ifaceMap[strtolower((string) $__k)] = $__i; }
    }
}
$__schedMap = [];
foreach (($schedules ?? []) as $__s) {
    $__schedMap[strtolower((string) $__s['name'])] = $__s;
}
$__ifaceTip = static function (string $n) use ($__ifaceMap): string {
    return $n !== '' && isset($__ifaceMap[strtolower($n)]) ? \App\Libraries\ObjectTip::iface($__ifaceMap[strtolower($n)]) : '';
};
$__schedTip = static function (string $n) use ($__schedMap): string {
    return $n !== '' && isset($__schedMap[strtolower($n)]) ? \App\Libraries\ObjectTip::schedule($__schedMap[strtolower($n)]) : '';
};
?>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <span>Firewall</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>NAT Policy</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:var(--tone-cyan-bg); color:var(--tone-cyan-fg); border:1px solid var(--tone-cyan-border);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
        </svg>
      </div>
      <div>
        <h1 class="page-title">Network Address Translation (NAT)</h1>
        <p class="page-subtitle">Named NAT objects referenced from firewall policies &mdash; destination NAT (DNAT rules), source NAT (SNAT pools), and NPTv6</p>
      </div>
    </div>
  </div>
  <div class="page-header-actions">
    <button type="button" class="btn btn-ghost btn-sm" data-compact-toggle onclick="micronToggleCompact()" aria-pressed="false" title="Toggle compact (stacked) table view">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
      <span data-compact-label>Compact view</span>
    </button>
    <a href="<?= base_url('firewall/rules') ?>" class="btn btn-ghost btn-sm">Firewall Policies</a>
    <?php if (session()->get('is_superadmin') || in_array('nat.apply', session()->get('permissions') ?? [])): ?>
      <form method="POST" action="<?= base_url('nat/apply') ?>" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="tab" value="<?= esc($activeTab) ?>">
        <button type="submit" class="btn btn-success btn-sm">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Apply Changes
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:20px;"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:20px;"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<!-- ── Tab Bar ── -->
<div class="tab-bar rt-tabs animate-in">
  <button type="button" class="tab-item rt-tab <?= $activeTab === 'dnat' ? 'active' : '' ?>" onclick="switchTab('dnat', this)">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    Destination NAT
    <span class="tab-badge rt-tab-count"><?= count($dnatRules) ?></span>
  </button>
  <button type="button" class="tab-item rt-tab <?= $activeTab === 'snat' ? 'active' : '' ?>" onclick="switchTab('snat', this)">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
    SNAT Pools
    <span class="tab-badge rt-tab-count"><?= count($snatPools) ?></span>
  </button>
  <button type="button" class="tab-item rt-tab <?= $activeTab === 'npt' ? 'active' : '' ?>" onclick="switchTab('npt', this)">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9"/></svg>
    NPTv6
    <span class="tab-badge rt-tab-count"><?= count($nptRules) ?></span>
  </button>
  <button type="button" class="tab-item rt-tab <?= $activeTab === 'settings' ? 'active' : '' ?>" onclick="switchTab('settings', this)">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
    Settings
  </button>
</div>

<!-- ══ TAB: Destination NAT ══ -->
<div class="rt-panel <?= $activeTab === 'dnat' ? 'active' : '' ?>" id="panel-dnat">
  <div class="card" style="padding:0; overflow-x:auto; margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 18px; border-bottom:1px solid var(--color-border);">
      <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">Destination NAT</span>
      <?php if (session()->get('is_superadmin') || in_array('nat.edit', session()->get('permissions') ?? [])): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openDnatModal()">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Create Destination NAT
        </button>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead><tr>
        <th>Name</th><th>Interface</th><th>External</th><th>Mapped (Internal)</th><th>Proto / Port</th><th>Schedule</th><th>Mode</th><th>Status</th><th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($dnatRules)): ?>
          <tr><td colspan="8" style="text-align:center; padding:32px; color:var(--text-muted);">No Destination NAT defined yet.</td></tr>
        <?php else: foreach ($dnatRules as $dnat): ?>
          <tr>
            <td data-label="Name">
              <strong style="color:var(--text-primary);"><?= esc($dnat['name']) ?></strong>
              <?php if (!empty($dnat['comment'])): ?><div style="font-size:0.7rem; color:var(--text-muted);"><?= esc($dnat['comment']) ?></div><?php endif; ?>
            </td>
            <td data-label="Interface"><span class="rt-chip"<?= $__ifaceTip((string) $dnat['interface_id']) ?>><?= esc(strtoupper($dnat['interface_id'])) ?></span></td>
            <td data-label="External"><span class="rt-chip rt-chip-cyan"><?= esc($dnat['external_ip']) ?><?= !empty($dnat['external_port']) ? ':' . esc($dnat['external_port']) : '' ?></span></td>
            <td data-label="Mapped"><span class="rt-chip rt-chip-blue"><?= esc($dnat['mapped_ip']) ?><?= !empty($dnat['mapped_port']) ? ':' . esc($dnat['mapped_port']) : '' ?></span></td>
            <td data-label="Protocol" style="font-size:0.78rem; color:var(--text-secondary);"><span><?= esc(strtoupper($dnat['protocol'])) ?></span></td>
            <td data-label="Schedule">
              <?php $isAlways = empty($dnat['schedule']) || strtolower($dnat['schedule']) === 'always'; ?>
              <span class="badge" style="<?= !$isAlways ? 'background:rgba(6,182,212,0.18); color:#67e8f9; font-weight:600;' : 'background:rgba(255,255,255,0.06); color:var(--text-muted);' ?> font-size:0.7rem; font-family:var(--font-mono);"<?= $__schedTip((string) ($dnat['schedule'] ?? 'always')) ?>>
                <?= esc(strtoupper($dnat['schedule'] ?? 'ALWAYS')) ?>
              </span>
            </td>
            <td data-label="Mode">
              <?= !empty($dnat['bidirectional']) ? '<span class="rt-chip rt-chip-amber">1:1</span>' : '<span class="rt-chip">DNAT</span>' ?>
              <?= !empty($dnat['nat_reflection']) ? '<span class="rt-chip" title="NAT reflection">+refl</span>' : '' ?>
            </td>
            <td data-label="Status"><?= !empty($dnat['enabled']) ? '<span class="badge badge-success">Enabled</span>' : '<span class="badge badge-muted">Disabled</span>' ?></td>
            <td data-label="Actions" style="text-align:right; white-space:nowrap;">
              <button type="button" class="btn btn-ghost btn-sm" onclick="editDnat(<?= htmlspecialchars(json_encode($dnat), ENT_QUOTES) ?>)">Edit</button>
              <form method="POST" action="<?= base_url('nat/dnat/' . $dnat['id'] . '/toggle') ?>" style="display:inline;"><?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:<?= !empty($dnat['enabled']) ? 'var(--color-emerald)' : 'var(--text-muted)' ?>;" title="<?= !empty($dnat['enabled']) ? 'Disable' : 'Enable' ?>">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728M12 3v9"/></svg>
                </button>
              </form>
              <form method="POST" action="<?= base_url('nat/dnat/' . $dnat['id'] . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Delete Destination NAT \'<?= esc($dnat['name'], 'js') ?>\'?');"><?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);" title="Delete">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <p class="page-subtitle" style="margin-top:-8px;">A Destination NAT maps a public address to an internal host. A port-forward is a DNAT with ports; a bi-directional DNAT is a 1:1 mapping. Reference the DNAT from a firewall policy to allow the translated traffic.</p>
</div>

<!-- ══ TAB: SNAT Pools ══ -->
<div class="rt-panel <?= $activeTab === 'snat' ? 'active' : '' ?>" id="panel-snat">
  <div class="card" style="padding:0; overflow-x:auto; margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 18px; border-bottom:1px solid var(--color-border);">
      <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">SNAT Pools (Source NAT Address Ranges)</span>
      <?php if (session()->get('is_superadmin') || in_array('nat.edit', session()->get('permissions') ?? [])): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openSnatPoolModal()">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Create SNAT Pool
        </button>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead><tr>
        <th>Name</th><th>Interface</th><th>Range</th><th>Addresses</th><th>Status</th><th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($snatPools)): ?>
          <tr><td colspan="6" style="text-align:center; padding:32px; color:var(--text-muted);">No SNAT Pools defined yet.</td></tr>
        <?php else: foreach ($snatPools as $snatPool): ?>
          <?php
            $count = 1;
            if (!empty($snatPool['end_ip'])) {
                $s = ip2long($snatPool['start_ip']); $e = ip2long($snatPool['end_ip']);
                $count = ($s !== false && $e !== false && $e >= $s) ? ($e - $s + 1) : 1;
            }
          ?>
          <tr>
            <td data-label="Name">
              <strong style="color:var(--text-primary);"><?= esc($snatPool['name']) ?></strong>
              <?php if (!empty($snatPool['comment'])): ?><div style="font-size:0.7rem; color:var(--text-muted);"><?= esc($snatPool['comment']) ?></div><?php endif; ?>
            </td>
            <td data-label="Interface"><span class="rt-chip"<?= $__ifaceTip((string) $snatPool['interface_id']) ?>><?= esc(strtoupper($snatPool['interface_id'])) ?></span></td>
            <td data-label="Range"><span class="rt-chip rt-chip-cyan"><?= esc($snatPool['start_ip']) ?><?= !empty($snatPool['end_ip']) && $snatPool['end_ip'] !== $snatPool['start_ip'] ? ' – ' . esc($snatPool['end_ip']) : '' ?></span></td>
            <td data-label="Addresses" style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);"><span><?= esc($count) ?></span></td>
            <td data-label="Status"><?= !empty($snatPool['enabled']) ? '<span class="badge badge-success">Enabled</span>' : '<span class="badge badge-muted">Disabled</span>' ?></td>
            <td data-label="Actions" style="text-align:right; white-space:nowrap;">
              <button type="button" class="btn btn-ghost btn-sm" onclick="editSnatPool(<?= htmlspecialchars(json_encode($snatPool), ENT_QUOTES) ?>)">Edit</button>
              <form method="POST" action="<?= base_url('nat/snat-pools/' . $snatPool['id'] . '/toggle') ?>" style="display:inline;"><?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:<?= !empty($snatPool['enabled']) ? 'var(--color-emerald)' : 'var(--text-muted)' ?>;" title="<?= !empty($snatPool['enabled']) ? 'Disable' : 'Enable' ?>">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728M12 3v9"/></svg>
                </button>
              </form>
              <form method="POST" action="<?= base_url('nat/snat-pools/' . $snatPool['id'] . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Delete SNAT Pool \'<?= esc($snatPool['name'], 'js') ?>\'?');"><?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);" title="Delete">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <p class="page-subtitle" style="margin-top:-8px;">An SNAT Pool is a range of external addresses used for outbound source NAT. Select one on a firewall policy's SNAT setting.</p>
</div>

<!-- ══ TAB: NPTv6 ══ -->
<div class="rt-panel <?= $activeTab === 'npt' ? 'active' : '' ?>" id="panel-npt">
  <div class="card" style="padding:0; overflow-x:auto; margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 18px; border-bottom:1px solid var(--color-border);">
      <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">Stateless IPv6 Prefix Translation (NPTv6)</span>
      <?php if (session()->get('is_superadmin') || in_array('nat.edit', session()->get('permissions') ?? [])): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('nptModal')">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Create NPTv6 Mapping
        </button>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead><tr>
        <th>Interface</th><th>Internal Prefix</th><th>External Prefix</th><th>Status</th><th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($nptRules)): ?>
          <tr><td colspan="5" style="text-align:center; padding:32px; color:var(--text-muted);">No NPTv6 mappings defined yet.</td></tr>
        <?php else: foreach ($nptRules as $npt): ?>
          <tr>
            <td data-label="Interface"><span class="rt-chip"<?= $__ifaceTip((string) $npt['interface_id']) ?>><?= esc(strtoupper($npt['interface_id'])) ?></span></td>
            <td data-label="Internal Prefix">
              <span class="rt-chip rt-chip-blue"><?= esc($npt['internal_prefix']) ?></span>
              <?php if (!empty($npt['description'])): ?><div style="font-size:0.7rem; color:var(--text-muted);"><?= esc($npt['description']) ?></div><?php endif; ?>
            </td>
            <td data-label="External Prefix"><span class="rt-chip rt-chip-cyan"><?= esc($npt['external_prefix']) ?></span></td>
            <td data-label="Status"><?= !empty($npt['enabled']) ? '<span class="badge badge-success">Enabled</span>' : '<span class="badge badge-muted">Disabled</span>' ?></td>
            <td data-label="Actions" style="text-align:right; white-space:nowrap;">
              <form method="POST" action="<?= base_url('nat/npt/' . $npt['id'] . '/toggle') ?>" style="display:inline;"><?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:<?= !empty($npt['enabled']) ? 'var(--color-emerald)' : 'var(--text-muted)' ?>;" title="<?= !empty($npt['enabled']) ? 'Disable' : 'Enable' ?>">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728M12 3v9"/></svg>
                </button>
              </form>
              <form method="POST" action="<?= base_url('nat/npt/' . $npt['id'] . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Delete this NPTv6 mapping?');"><?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);" title="Delete">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <p class="page-subtitle" style="margin-top:-8px;">Stateless IPv6 Network Prefix Translation (RFC 6296) between an internal ULA prefix and an external GUA prefix.</p>
</div>

<!-- ══ TAB: Settings ══ -->
<div class="rt-panel <?= $activeTab === 'settings' ? 'active' : '' ?>" id="panel-settings">
  <div class="card" style="max-width:680px;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border);">
      <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">Global NAT Settings</span>
    </div>
    <form method="POST" action="<?= base_url('nat/settings') ?>">
      <?= csrf_field() ?>
      <div style="padding:18px;">
        <div class="rt-field">
          <label>Outbound NAT Mode</label>
          <select name="outbound_mode">
            <option value="automatic" <?= ($settings['outbound_mode'] ?? '') === 'automatic' ? 'selected' : '' ?>>Automatic — masquerade every WAN egress</option>
            <option value="manual"    <?= ($settings['outbound_mode'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual — only rules you define on policies</option>
            <option value="disabled"  <?= ($settings['outbound_mode'] ?? '') === 'disabled' ? 'selected' : '' ?>>Disabled — no automatic source NAT</option>
          </select>
          <p class="hint">Controls the automatic WAN masquerade; per-policy SNAT via an SNAT pool still applies.</p>
        </div>
        <div class="rt-field">
          <label class="rt-check">
            <input type="checkbox" name="nat_reflection" value="1" <?= !empty($settings['nat_reflection']) ? 'checked' : '' ?>>
            Enable NAT Reflection (hairpin)
          </label>
          <p class="hint">LAN clients reaching a public Destination NAT are redirected to the internal host.</p>
        </div>
        <div class="rt-field" style="max-width:220px;">
          <label>Reflection Timeout (seconds)</label>
          <input type="number" name="reflection_timeout" min="10" value="<?= esc($settings['reflection_timeout'] ?? 30) ?>">
        </div>
      </div>
      <div class="rt-modal-footer" style="border-top:1px solid var(--color-border); border-radius:0 0 var(--radius-md) var(--radius-md);">
        <button type="submit" class="btn btn-primary btn-sm">Save Settings</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODALS ══ -->

<!-- Destination NAT Modal -->
<div class="rt-modal-backdrop" id="dnatModal">
  <div class="rt-modal-dialog">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="dnatModalTitle">New Destination NAT</span>
      <button type="button" onclick="closeModal('dnatModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="dnatForm" action="<?= base_url('nat/dnat/store') ?>">
      <?= csrf_field() ?>
      <div class="rt-modal-body">
        <div class="rt-field"><label>Name *</label><input type="text" name="name" id="dnatName" required maxlength="64" placeholder="Web_Server_VIP"></div>
        <div class="rt-field">
          <label>External Interface</label>
          <select name="interface_id" id="dnatIface" data-fw-iface>
            <?php foreach ($interfaces as $iface): ?>
              <option value="<?= esc($iface['id']) ?>"<?= \App\Libraries\ObjectTip::iface($iface) ?>><?= esc($iface['name']) ?> (<?= esc($iface['physical_device'] ?: $iface['name']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field"><label>External IP *</label><input type="text" name="external_ip" id="dnatExtIp" required placeholder="203.0.113.10"></div>
          <div class="rt-field"><label>External Port</label><input type="number" name="external_port" id="dnatExtPort" min="1" max="65535" placeholder="443"><p class="hint">Optional — leave blank for all ports</p></div>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field"><label>Mapped (Internal) IP *</label><input type="text" name="mapped_ip" id="dnatMappedIp" required placeholder="192.168.1.50"></div>
          <div class="rt-field"><label>Mapped Port</label><input type="number" name="mapped_port" id="dnatMappedPort" min="1" max="65535" placeholder="8443"><p class="hint">Defaults to the external port</p></div>
        </div>
        <div class="rt-field">
          <label>Protocol</label>
          <select name="protocol" id="dnatProto">
            <option value="tcp">TCP</option><option value="udp">UDP</option><option value="tcp/udp">TCP/UDP</option><option value="any">ANY</option>
          </select>
        </div>
        <div class="rt-field">
          <label style="display:flex; justify-content:space-between; align-items:center;">
            <span>Schedule</span>
            <a href="<?= base_url('firewall/schedules') ?>" target="_blank" style="color:var(--color-cyan); text-decoration:none; font-size:0.7rem;">+ Manage Schedules</a>
          </label>
          <select name="schedule" id="dnatSchedule" data-fw-select>
            <?php foreach (($schedules ?? []) as $sc): ?>
              <option value="<?= esc($sc['name']) ?>"<?= \App\Libraries\ObjectTip::schedule($sc) ?>>
                <?= esc(ucwords(str_replace('_', ' ', $sc['name']))) ?> (<?= esc(\App\Models\ScheduleModel::formatSummary($sc)) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rt-field"><label>Comment</label><input type="text" name="comment" id="dnatComment" maxlength="255" placeholder="Optional note"></div>
        <div class="rt-field">
          <label class="rt-check"><input type="checkbox" name="nat_reflection" value="1" id="dnatReflection" checked> Enable NAT Reflection (hairpin)</label>
        </div>
        <div class="rt-field">
          <label class="rt-check"><input type="checkbox" name="bidirectional" value="1" id="dnatBidir"> Bi-directional (1:1 NAT) — internal host egress uses the public address</label>
        </div>
        <input type="hidden" name="enabled" value="1">
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('dnatModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save Destination NAT</button>
      </div>
    </form>
  </div>
</div>

<!-- SNAT Pool Modal -->
<div class="rt-modal-backdrop" id="snatPoolModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="snatPoolModalTitle">New SNAT Pool</span>
      <button type="button" onclick="closeModal('snatPoolModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="snatPoolForm" action="<?= base_url('nat/snat-pools/store') ?>">
      <?= csrf_field() ?>
      <div class="rt-modal-body">
        <div class="rt-field"><label>Name *</label><input type="text" name="name" id="snatPoolName" required maxlength="64" placeholder="SNAT_Pool_1"></div>
        <div class="rt-field">
          <label>Egress Interface</label>
          <select name="interface_id" id="snatPoolIface" data-fw-iface>
            <?php foreach ($interfaces as $iface): ?>
              <option value="<?= esc($iface['id']) ?>"<?= \App\Libraries\ObjectTip::iface($iface) ?>><?= esc($iface['name']) ?> (<?= esc($iface['physical_device'] ?: $iface['name']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field"><label>Start IP *</label><input type="text" name="start_ip" id="snatPoolStart" required placeholder="203.0.113.20"></div>
          <div class="rt-field"><label>End IP</label><input type="text" name="end_ip" id="snatPoolEnd" placeholder="203.0.113.30"><p class="hint">Optional range end</p></div>
        </div>
        <div class="rt-field"><label>Comment</label><input type="text" name="comment" id="snatPoolComment" maxlength="255" placeholder="Optional note"></div>
        <input type="hidden" name="enabled" value="1">
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('snatPoolModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save SNAT Pool</button>
      </div>
    </form>
  </div>
</div>

<!-- NPTv6 Modal -->
<div class="rt-modal-backdrop" id="nptModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">New NPTv6 Mapping</span>
      <button type="button" onclick="closeModal('nptModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="nptForm" action="<?= base_url('nat/npt/store') ?>">
      <?= csrf_field() ?>
      <div class="rt-modal-body">
        <div class="rt-field">
          <label>Interface</label>
          <select name="interface_id" id="nptIface" data-fw-iface>
            <?php foreach ($interfaces as $iface): ?>
              <option value="<?= esc($iface['id']) ?>"<?= \App\Libraries\ObjectTip::iface($iface) ?>><?= esc($iface['name']) ?> (<?= esc($iface['physical_device'] ?: $iface['name']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rt-field"><label>Internal Prefix *</label><input type="text" name="internal_prefix" id="nptInternal" required placeholder="fd00:abcd:1::/64"></div>
        <div class="rt-field"><label>External Prefix *</label><input type="text" name="external_prefix" id="nptExternal" required placeholder="2001:db8:acad:1::/64"></div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" id="nptDesc" maxlength="255"></div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('nptModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save Mapping</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchTab(name, btn) {
  document.querySelectorAll('.rt-panel').forEach(function(p) { p.classList.remove('active'); });
  document.querySelectorAll('.rt-tab').forEach(function(t) { t.classList.remove('active'); });
  var panel = document.getElementById('panel-' + name);
  if (panel) panel.classList.add('active');
  if (btn) btn.classList.add('active');
  var url = new URL(window.location);
  url.searchParams.set('tab', name);
  history.replaceState(null, '', url);
}
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

// NAT is WAN-facing by default; fall back to the first option if there is no WAN.
function defaultToWan(selectId) {
  var sel = document.getElementById(selectId);
  if (sel && [...sel.options].some(o => o.value === 'wan')) sel.value = 'wan';
}

document.querySelectorAll('.rt-modal-backdrop').forEach(function(m) {
  m.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
});

function openDnatModal() {
  document.getElementById('dnatModalTitle').innerText = 'New Destination NAT';
  document.getElementById('dnatForm').action = '<?= base_url('nat/dnat/store') ?>';
  document.getElementById('dnatForm').reset();
  document.getElementById('dnatReflection').checked = true;
  document.getElementById('dnatBidir').checked = false;
  document.getElementById('dnatSchedule').value = 'always';
  defaultToWan('dnatIface');
  openModal('dnatModal');
}
function editDnat(dnat) {
  document.getElementById('dnatModalTitle').innerText = 'Edit Destination NAT: ' + dnat.name;
  document.getElementById('dnatForm').action = '<?= base_url('nat/dnat') ?>/' + encodeURIComponent(dnat.id) + '/update';
  document.getElementById('dnatName').value = dnat.name || '';
  document.getElementById('dnatIface').value = dnat.interface_id || 'wan';
  document.getElementById('dnatExtIp').value = dnat.external_ip || '';
  document.getElementById('dnatExtPort').value = dnat.external_port || '';
  document.getElementById('dnatMappedIp').value = dnat.mapped_ip || '';
  document.getElementById('dnatMappedPort').value = dnat.mapped_port || '';
  document.getElementById('dnatProto').value = dnat.protocol || 'tcp';
  document.getElementById('dnatSchedule').value = dnat.schedule || 'always';
  document.getElementById('dnatComment').value = dnat.comment || '';
  document.getElementById('dnatReflection').checked = parseInt(dnat.nat_reflection) === 1;
  document.getElementById('dnatBidir').checked = parseInt(dnat.bidirectional) === 1;
  openModal('dnatModal');
}

function openSnatPoolModal() {
  document.getElementById('snatPoolModalTitle').innerText = 'New SNAT Pool';
  document.getElementById('snatPoolForm').action = '<?= base_url('nat/snat-pools/store') ?>';
  document.getElementById('snatPoolForm').reset();
  defaultToWan('snatPoolIface');
  openModal('snatPoolModal');
}
function editSnatPool(snatPool) {
  document.getElementById('snatPoolModalTitle').innerText = 'Edit SNAT Pool: ' + snatPool.name;
  document.getElementById('snatPoolForm').action = '<?= base_url('nat/snat-pools') ?>/' + encodeURIComponent(snatPool.id) + '/update';
  document.getElementById('snatPoolName').value = snatPool.name || '';
  document.getElementById('snatPoolIface').value = snatPool.interface_id || 'wan';
  document.getElementById('snatPoolStart').value = snatPool.start_ip || '';
  document.getElementById('snatPoolEnd').value = snatPool.end_ip || '';
  document.getElementById('snatPoolComment').value = snatPool.comment || '';
  openModal('snatPoolModal');
}
</script>

<?= $this->endSection() ?>
