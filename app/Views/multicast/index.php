<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?php
  /** @var array<string,mixed> $settings */
  /** @var array<string,mixed> $compiled */
  /** @var array<string,mixed> $status */
  /** @var list<array{id:string,label:string}> $interfaces */
  $on = static fn ($v) => ! empty($v);
  $badge = [
    'running' => ['color-emerald', 'RUNNING'],
    'stopped' => ['color-muted', 'STOPPED'],
    'unknown' => ['color-muted', 'UNKNOWN'],
  ];
?>

<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <h1 class="page-title">Multicast &amp; IGMP</h1>
      <p class="page-subtitle">IGMP proxy for upstream multicast (IPTV), multicast routing with FRR PIM/IGMP, and bridge snooping with mDNS reflection across VLANs.</p>
    </div>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
      <?= \App\Libraries\ServiceStatus::widget(
            ['igmpproxy', 'frr', 'mdns-repeater'],
            ['igmpproxy' => 'IGMP', 'frr' => 'PIM', 'mdns-repeater' => 'mDNS'],
          ) ?>
      <?php if ($isMock): ?>
        <span class="badge badge-warning">⚠ Mock Mode</span>
      <?php endif; ?>
      <form method="POST" action="<?= base_url('multicast/apply') ?>" style="margin:0;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-secondary btn-sm">Apply Configuration</button>
      </form>
    </div>
  </div>
</div>

<?php if ($isMock): ?>
  <div class="alert animate-in" data-persist style="margin-bottom:18px; background:var(--color-surface-2); border:1px solid var(--color-border); color:var(--text-secondary); font-size:0.8rem;">
    ⚠ Mock mode — the multicast daemons are simulated on this host. Configurations are compiled and stored, but no igmpproxy, FRR PIM/IGMP or mDNS repeater process is started here.
  </div>
<?php endif; ?>

<?php if (!empty($compiled['warnings'])): ?>
  <div class="card card-glass animate-in" style="padding:12px 18px; margin-bottom:18px; border-left:3px solid var(--color-amber, #f59e0b);">
    <div style="font-size:0.78rem; font-weight:700; color:#fcd34d; margin-bottom:4px;">Configuration warnings</div>
    <?php foreach ($compiled['warnings'] as $warning): ?>
      <div style="font-size:0.75rem; color:var(--text-secondary);">&bull; <?= esc($warning) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" action="<?= base_url('multicast/save') ?>">
  <?= csrf_field() ?>

  <div class="card card-glass animate-in" style="padding:16px 18px; margin-bottom:18px;">
    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:700; font-size:0.85rem; color:var(--text-primary); margin:0;">
      <input type="checkbox" name="enabled" value="1" <?= $on($settings['enabled']) ? 'checked' : '' ?> style="width:16px; height:16px; accent-color:var(--color-cyan);">
      Enable Multicast &amp; IGMP services
    </label>
    <small style="font-size:0.7rem; color:var(--text-muted);">Master switch. Individual capabilities below are only applied while this is on.</small>
  </div>

  <!-- 1. IGMP proxy -->
  <div class="card card-glass animate-in" style="margin-bottom:18px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
      <span class="card-title">IGMP Proxy (Upstream Multicast / IPTV)</span>
      <label style="display:flex; align-items:center; gap:8px; font-size:0.78rem; font-weight:600; margin:0;">
        <input type="checkbox" name="igmp_proxy_enabled" value="1" <?= $on($settings['igmp_proxy_enabled']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);"> Enable igmpproxy
      </label>
    </div>
    <div style="padding:16px 18px; display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:16px;">
      <div>
        <label class="form-label">Upstream Interface (WAN)</label>
        <input list="mcIfaces" type="text" name="igmp_upstream" value="<?= esc($settings['igmp_upstream'] ?? '') ?>" class="form-control" placeholder="wan0" style="font-family:var(--font-mono);">
      </div>
      <div>
        <label class="form-label">Downstream Interfaces (LAN)</label>
        <textarea name="igmp_downstreams" rows="2" class="form-control" placeholder="lan0&#10;opt1" style="font-family:var(--font-mono); font-size:0.78rem;"><?= esc($settings['igmp_downstreams'] ?? '') ?></textarea>
        <small style="font-size:0.7rem; color:var(--text-muted);">One per line. Multicast groups learned upstream are proxied onto these.</small>
      </div>
    </div>
    <div style="padding:0 18px 16px; display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:16px;">
      <div>
        <label class="form-label">Extra Upstream Sources (altnet)</label>
        <textarea name="igmp_altnets" rows="3" class="form-control" placeholder="10.0.0.0/8" style="font-family:var(--font-mono); font-size:0.78rem;"><?= esc($settings['igmp_altnets'] ?? '') ?></textarea>
        <small style="font-size:0.7rem; color:var(--text-muted);">IP/CIDR entries allowed as multicast sources on the upstream side.</small>
      </div>
      <div>
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.78rem; font-weight:600;">
          <input type="checkbox" name="igmp_quickleave" value="1" <?= $on($settings['igmp_quickleave']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
          quickleave
        </label>
        <small style="font-size:0.7rem; color:var(--text-muted);">Stop forwarding a group as soon as a client leaves it (faster channel changes).</small>
      </div>
    </div>
  </div>

  <!-- 2. Multicast routing -->
  <div class="card card-glass animate-in" style="margin-bottom:18px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
      <span class="card-title">Multicast Routing (FRR PIM + IGMP)</span>
      <label style="display:flex; align-items:center; gap:8px; font-size:0.78rem; font-weight:600; margin:0;">
        <input type="checkbox" name="pim_enabled" value="1" <?= $on($settings['pim_enabled']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);"> Enable PIM/IGMP
      </label>
    </div>
    <div style="padding:16px 18px; display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px;">
      <div>
        <label class="form-label">Mode</label>
        <select name="pim_mode" class="form-control">
          <option value="sparse" <?= ($settings['pim_mode'] ?? '') === 'sparse' ? 'selected' : '' ?>>PIM-SM (Sparse, with RP)</option>
          <option value="ssm" <?= ($settings['pim_mode'] ?? '') === 'ssm' ? 'selected' : '' ?>>PIM-SSM (Source-Specific)</option>
        </select>
      </div>
      <div>
        <label class="form-label">Rendezvous Point (sparse)</label>
        <input type="text" name="pim_rp_address" value="<?= esc($settings['pim_rp_address'] ?? '') ?>" class="form-control" placeholder="10.0.0.1" style="font-family:var(--font-mono);">
      </div>
      <div>
        <label class="form-label">SSM Range</label>
        <input type="text" name="pim_ssm_range" value="<?= esc($settings['pim_ssm_range'] ?? '232.0.0.0/8') ?>" class="form-control" style="font-family:var(--font-mono);">
      </div>
    </div>
    <div style="padding:0 18px 16px; display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:16px;">
      <div>
        <label class="form-label">PIM Interfaces</label>
        <textarea name="pim_interfaces" rows="3" class="form-control" placeholder="lan0&#10;opt1" style="font-family:var(--font-mono); font-size:0.78rem;"><?= esc($settings['pim_interfaces'] ?? '') ?></textarea>
        <small style="font-size:0.7rem; color:var(--text-muted);">Interfaces that run PIM and, below, IGMP.</small>
      </div>
      <div>
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.78rem; font-weight:600;">
          <input type="checkbox" name="igmp_querier_enabled" value="1" <?= $on($settings['igmp_querier_enabled']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
          Enable IGMP on the selected interfaces
        </label>
        <div style="margin-top:8px;">
          <label class="form-label" style="font-size:0.72rem;">IGMP Version</label>
          <select name="igmp_version" class="form-control" style="max-width:140px;">
            <?php foreach ([1, 2, 3] as $v): ?>
              <option value="<?= $v ?>" <?= (int) ($settings['igmp_version'] ?? 3) === $v ? 'selected' : '' ?>>IGMPv<?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <!-- 3. Snooping + mDNS -->
  <div class="card card-glass animate-in" style="margin-bottom:18px;">
    <div class="card-header"><span class="card-title">Bridge Snooping &amp; mDNS Reflection</span></div>
    <div style="padding:16px 18px; display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:18px;">
      <div>
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8rem; font-weight:600; margin-bottom:8px;">
          <input type="checkbox" name="snooping_enabled" value="1" <?= $on($settings['snooping_enabled']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
          Enable bridge multicast snooping
        </label>
        <input list="mcIfaces" type="text" name="snooping_bridge" value="<?= esc($settings['snooping_bridge'] ?? '') ?>" class="form-control" placeholder="br-lan" style="font-family:var(--font-mono); margin-bottom:8px;">
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.78rem; font-weight:600;">
          <input type="checkbox" name="snooping_querier" value="1" <?= $on($settings['snooping_querier']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
          Act as IGMP querier
        </label>
        <div style="margin-top:8px;">
          <label class="form-label" style="font-size:0.72rem;">Query Interval (seconds)</label>
          <input type="number" name="snooping_query_interval" min="1" max="3600" value="<?= (int) ($settings['snooping_query_interval'] ?? 125) ?>" class="form-control" style="max-width:160px; font-family:var(--font-mono);">
        </div>
      </div>
      <div>
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8rem; font-weight:600; margin-bottom:8px;">
          <input type="checkbox" name="mdns_enabled" value="1" <?= $on($settings['mdns_enabled']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
          Enable mDNS / Bonjour reflection
        </label>
        <textarea name="mdns_interfaces" rows="2" class="form-control" placeholder="br-lan&#10;br-iot" style="font-family:var(--font-mono); font-size:0.78rem; margin-bottom:8px;"><?= esc($settings['mdns_interfaces'] ?? '') ?></textarea>
        <label class="form-label" style="font-size:0.72rem;">Mode</label>
        <select name="mdns_mode" class="form-control" style="max-width:200px;">
          <option value="reflect" <?= ($settings['mdns_mode'] ?? '') === 'reflect' ? 'selected' : '' ?>>Reflect all mDNS</option>
          <option value="relay" <?= ($settings['mdns_mode'] ?? '') === 'relay' ? 'selected' : '' ?>>Relay with filter file</option>
        </select>
        <small style="font-size:0.7rem; color:var(--text-muted);">So Chromecast, printers and cameras on one VLAN are visible on another.</small>
      </div>
    </div>
  </div>

  <div style="display:flex; justify-content:flex-end; margin-bottom:22px;">
    <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px;">
      Save &amp; Apply Multicast Configuration
    </button>
  </div>
</form>

<!-- Compiled artifact preview -->
<div class="card card-glass animate-in">
  <div class="card-header"><span class="card-title">Generated Configuration Preview</span></div>
  <div style="padding:16px 18px; display:grid; gap:14px;">
    <div>
      <label class="form-label" style="font-size:0.75rem;">/etc/igmpproxy.conf</label>
      <textarea readonly rows="6" class="form-control" style="font-family:var(--font-mono); font-size:0.72rem;"><?= esc($compiled['igmp_proxy'] ?: '# igmpproxy disabled') ?></textarea>
    </div>
    <div>
      <label class="form-label" style="font-size:0.75rem;">FRR PIM/IGMP command script</label>
      <textarea readonly rows="6" class="form-control" style="font-family:var(--font-mono); font-size:0.72rem;"><?= esc($compiled['pim'] ?: '# PIM/IGMP disabled') ?></textarea>
    </div>
    <div>
      <label class="form-label" style="font-size:0.75rem;">mDNS repeater</label>
      <textarea readonly rows="2" class="form-control" style="font-family:var(--font-mono); font-size:0.72rem;"><?= esc($compiled['mdns']['command'] ?: '# mDNS reflection disabled') ?></textarea>
    </div>
  </div>
</div>

<datalist id="mcIfaces">
  <?php foreach ($interfaces as $iface): ?>
    <option value="<?= esc($iface['id']) ?>"><?= esc($iface['label']) ?></option>
  <?php endforeach; ?>
</datalist>

<?= $this->endSection() ?>
