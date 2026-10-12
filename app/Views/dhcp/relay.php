<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.relay-table { width:100%; border-collapse:separate; border-spacing:0; font-size:0.82rem; }
.relay-table th {
  background:var(--color-surface-3); color:var(--text-secondary); font-weight:600; font-size:0.72rem;
  text-transform:uppercase; letter-spacing:0.05em; padding:10px 14px; border-bottom:1px solid var(--color-border); text-align:left;
}
.relay-table td { padding:10px 14px; border-bottom:1px solid var(--color-border); vertical-align:middle; }
.relay-table tbody tr:hover td { background:rgba(255,255,255,0.02); }
.relay-chip {
  display:inline-flex; align-items:center; gap:4px; padding:2px 7px; border-radius:4px; font-family:var(--font-mono);
  font-size:0.7rem; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.12); color:var(--text-secondary); margin:1px 2px 1px 0;
}
.relay-plan-inst { border:1px solid var(--color-border); border-radius:8px; padding:10px 12px; margin-bottom:8px; background:var(--color-surface-3); }
.relay-note { border-radius:8px; padding:10px 12px; margin-bottom:10px; font-size:.8rem; }
.relay-note.err  { background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.3); color:#fca5a5; }
.relay-note.warn { background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.3); color:#fcd34d; }
.relay-inline { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
.relay-field label { display:block; font-size:0.7rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:4px; }
.relay-field input, .relay-field select {
  background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary);
  padding:6px 10px; border-radius:var(--radius-sm); font-size:0.82rem;
}
</style>

<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <a href="<?= base_url('dhcp') ?>">DHCP</a>
      <span class="sep">/</span>
      <span class="current">Relay</span>
    </div>
    <div style="display:flex; align-items:center; gap:10px;">
      <div class="page-title-icon tone-cyan">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
        </svg>
      </div>
      <h1 class="page-title" style="margin:0;">DHCP Relay</h1>
      <span class="badge badge-info" style="font-size:0.75rem;"><?= count($relays) ?> Relay(s)</span>
      <?php if ($isMock): ?><span class="badge badge-muted" style="font-size:0.75rem;">MOCK MODE</span><?php endif; ?>
    </div>
    <p class="page-subtitle">Forward DHCP/BOOTP broadcasts from an interface to a DHCP server on another segment, using the maintained <code>dhcp-helper</code> agent.</p>
  </div>
  <div class="page-header-actions">
    <?= \App\Libraries\ServiceStatus::relayWidget($plan['instances'], $relayStates ?? []) ?>
    <a href="<?= base_url('dhcp') ?>" class="btn btn-ghost btn-sm">DHCP Server</a>
    <form method="POST" action="<?= base_url('dhcp/relay/apply') ?>" style="display:inline; margin:0;"
          onsubmit="return confirm('Apply the DHCP relay configuration now?');">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary btn-sm">Apply Relay</button>
    </form>
  </div>
</div>

<?php if (! empty($plan['conflicts'])): ?>
  <div class="relay-note err animate-in">
    <strong>Cannot apply:</strong>
    <ul style="margin:6px 0 0 18px;">
      <?php foreach ($plan['conflicts'] as $c): ?><li><?= esc($c) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php
// `$relayStates` (from dhcp.relay_status) contains only the units that actually
// exist on the daemon, keyed by slug. Presence therefore means "applied", and a
// compiled slug missing from it is configured-but-not-applied.
$relayStates = $relayStates ?? [];
$planSlugs   = [];
$applied     = 0;
foreach ($plan['instances'] as $__inst) {
    $__slug = (string) ($__inst['slug'] ?? '');
    if ($__slug === '') {
        continue;
    }
    $planSlugs[$__slug] = true;
    if (array_key_exists($__slug, $relayStates)) {
        $applied++;
    }
}
$configured  = count($planSlugs);
$notApplied  = $configured - $applied;
// Units present on the daemon but no longer in the compiled plan (e.g. left
// behind after a destination was renamed or disabled) — apply reconciles them.
$orphans     = array_diff_key($relayStates, $planSlugs);
?>
<div class="card card-glass animate-in animate-in-delay-1" style="padding:16px 18px; margin-bottom:16px;">
  <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:8px;">
    <div style="font-weight:700; font-size:0.95rem;">Compiled plan</div>
    <?php if ($configured > 0): ?>
      <span class="badge badge-info" style="font-size:.68rem;"><?= $configured ?> configured</span>
      <span class="badge <?= $applied > 0 ? 'badge-success' : 'badge-muted' ?>" style="font-size:.68rem;"><?= $applied ?> applied on daemon</span>
      <?php if ($notApplied > 0): ?>
        <span class="badge badge-warning" style="font-size:.68rem;"><?= $notApplied ?> not applied</span>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if ($plan['instances'] === []): ?>
    <div style="font-size:.82rem; color:var(--text-muted);">No relays are enabled — applying will stop any running relay instances.</div>
  <?php else: ?>
    <?php foreach ($plan['instances'] as $inst): ?>
      <?php $slug = (string) ($inst['slug'] ?? ''); $daemonState = $relayStates[$slug] ?? null; ?>
      <div class="relay-plan-inst">
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
          <span style="font-weight:600; font-size:.86rem;"><?= esc($inst['name']) ?></span>
          <span class="relay-chip"><?= esc($inst['unit']) ?></span>
          <?php if ($daemonState === 'running'): ?>
            <span class="badge badge-success" style="font-size:.66rem;">ON DAEMON · RUNNING</span>
          <?php elseif ($daemonState !== null): ?>
            <span class="badge badge-danger" style="font-size:.66rem;">ON DAEMON · <?= esc(strtoupper($daemonState)) ?></span>
          <?php else: ?>
            <span class="badge badge-warning" style="font-size:.66rem;">NOT APPLIED</span>
          <?php endif; ?>
        </div>
        <div style="margin-top:6px; font-size:.76rem; color:var(--text-muted);">
          Interfaces:
          <?php foreach ($inst['interfaces'] as $iface): ?><span class="relay-chip"><?= esc($iface) ?></span><?php endforeach; ?>
          &nbsp;Servers:
          <?php foreach ($inst['servers'] as $server): ?><span class="relay-chip"><?= esc($server) ?></span><?php endforeach; ?>
        </div>
        <div style="margin-top:6px; font-size:.72rem; color:var(--text-muted);">
          <?php if ($daemonState === null): ?>
            Configured here, but no <code><?= esc($inst['unit']) ?></code> exists on the daemon yet — apply the relay to install it.
          <?php else: ?>
            Unit <code><?= esc($inst['unit']) ?></code> exists on the daemon (<?= esc($daemonState) ?>). Live start / restart / stop is in the page header.
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($orphans !== []): ?>
    <div class="relay-note warn" style="margin-top:8px;">
      ⚠ On the daemon but not in this plan:
      <?php foreach ($orphans as $slug => $state): ?><span class="relay-chip"><?= esc((string) $slug) ?> · <?= esc((string) $state) ?></span><?php endforeach; ?>
      — apply the relay to remove stale helper units.
    </div>
  <?php endif; ?>

  <?php foreach ($plan['warnings'] as $w): ?>
    <div class="relay-note warn" style="margin-top:8px;">⚠ <?= esc($w) ?></div>
  <?php endforeach; ?>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;" class="animate-in animate-in-delay-2">
  <!-- Destinations -->
  <div class="card card-glass" style="padding:0; overflow:hidden;">
    <div style="padding:14px 16px; border-bottom:1px solid var(--color-border);">
      <div style="font-weight:700; font-size:0.9rem;">Destinations</div>
      <div style="font-size:.74rem; color:var(--text-muted); margin-top:2px;">Reusable DHCP server lists.</div>
    </div>
    <table class="relay-table">
      <thead><tr><th>Name</th><th>Servers</th><th style="width:70px; text-align:right;"></th></tr></thead>
      <tbody>
        <?php if ($destinations === []): ?>
          <tr><td colspan="3" style="color:var(--text-muted); font-size:.8rem;">No destinations yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($destinations as $d): ?>
          <tr>
            <td><?= esc($d['name']) ?></td>
            <td><?php foreach (explode(',', (string) $d['servers']) as $s): ?><span class="relay-chip"><?= esc($s) ?></span><?php endforeach; ?></td>
            <td style="text-align:right;">
              <form method="POST" action="<?= base_url('dhcp/relay/destinations/' . esc($d['id'], 'raw') . '/delete') ?>" style="display:inline;"
                    onsubmit="return confirm('Delete destination <?= esc($d['name'], 'js') ?>?');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 8px; font-size:.72rem;">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <form method="POST" action="<?= base_url('dhcp/relay/destinations/store') ?>" class="relay-inline" style="padding:14px 16px;">
      <?= csrf_field() ?>
      <div class="relay-field"><label>Name</label><input type="text" name="name" placeholder="Branch DHCP" required></div>
      <div class="relay-field" style="flex:1;"><label>Server IP(s)</label><input type="text" name="servers" placeholder="10.0.0.10, 10.0.0.11" style="width:100%;" required></div>
      <button type="submit" class="btn btn-primary btn-sm">Add</button>
    </form>
  </div>

  <!-- Relays -->
  <div class="card card-glass" style="padding:0; overflow:hidden;">
    <div style="padding:14px 16px; border-bottom:1px solid var(--color-border);">
      <div style="font-weight:700; font-size:0.9rem;">Relayed Interfaces</div>
      <div style="font-size:.74rem; color:var(--text-muted); margin-top:2px;">An interface cannot run the DHCP server and a relay together (UDP/67).</div>
    </div>
    <table class="relay-table">
      <thead><tr><th>Interface</th><th>Destination</th><th>Status</th><th style="width:130px; text-align:right;"></th></tr></thead>
      <tbody>
        <?php if ($relays === []): ?>
          <tr><td colspan="4" style="color:var(--text-muted); font-size:.8rem;">No relays configured.</td></tr>
        <?php endif; ?>
        <?php foreach ($relays as $r): ?>
          <tr>
            <td><span class="relay-chip"><?= esc($r['interface_id']) ?></span></td>
            <td><?= $r['destination_name'] ? esc($r['destination_name']) : '<span style="color:var(--text-muted);">—</span>' ?></td>
            <td>
              <?php if ((int) $r['enabled'] === 1): ?>
                <span class="badge badge-success" style="font-size:0.65rem;">ENABLED</span>
              <?php else: ?>
                <span class="badge badge-muted" style="font-size:0.65rem;">DISABLED</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right; white-space:nowrap;">
              <form method="POST" action="<?= base_url('dhcp/relay/interfaces/' . esc($r['id'], 'raw') . '/toggle') ?>" style="display:inline;">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 8px; font-size:.72rem;"><?= (int) $r['enabled'] === 1 ? 'Disable' : 'Enable' ?></button>
              </form>
              <form method="POST" action="<?= base_url('dhcp/relay/interfaces/' . esc($r['id'], 'raw') . '/delete') ?>" style="display:inline;"
                    onsubmit="return confirm('Remove this relay?');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 8px; font-size:.72rem;">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <form method="POST" action="<?= base_url('dhcp/relay/interfaces/store') ?>" class="relay-inline" style="padding:14px 16px;">
      <?= csrf_field() ?>
      <div class="relay-field">
        <label>Interface</label>
        <select name="interface_id" required>
          <?php foreach ($interfaces as $iface): ?>
            <option value="<?= esc($iface['id'] ?? $iface['name'] ?? '', 'raw') ?>"><?= esc($iface['name'] ?? $iface['id'] ?? '') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="relay-field">
        <label>Destination</label>
        <select name="destination_id">
          <option value="">— none —</option>
          <?php foreach ($destinations as $d): ?>
            <option value="<?= esc($d['id'], 'raw') ?>"><?= esc($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Add Relay</button>
    </form>
  </div>
</div>

<?= $this->endSection() ?>
