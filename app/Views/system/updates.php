<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.sysupd-table { width:100%; border-collapse:separate; border-spacing:0; font-size:0.82rem; }
.sysupd-table th {
  background:var(--color-surface-3); color:var(--text-secondary); font-weight:600; font-size:0.72rem;
  text-transform:uppercase; letter-spacing:0.05em; padding:10px 14px; border-bottom:1px solid var(--color-border); text-align:left;
}
.sysupd-table td { padding:9px 14px; border-bottom:1px solid var(--color-border); vertical-align:middle; font-family:var(--font-mono); font-size:0.78rem; }
.sysupd-table tbody tr:hover td { background:rgba(255,255,255,0.02); }
.sysupd-meta { display:grid; grid-template-columns:150px 1fr; gap:8px 16px; font-size:0.84rem; }
.sysupd-meta dt { color:var(--text-muted); font-weight:600; }
.sysupd-meta dd { margin:0; color:var(--text-primary); font-family:var(--font-mono); font-size:0.78rem; }
</style>

<div class="page-header animate-in">
  <div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <h1 class="page-title">System Updates</h1>
      <span class="badge badge-info" style="font-size:0.75rem;">Appliance</span>
      <?php if (($report['mock'] ?? false) === true): ?>
        <span class="badge badge-warning" style="font-size:0.62rem;">DEV / MOCK</span>
      <?php endif; ?>
    </div>
    <p class="page-subtitle">Check for and apply operating-system and package updates across the whole appliance</p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
    <a href="<?= base_url('system') ?>" class="btn btn-ghost btn-sm">System Settings</a>
    <form method="POST" action="<?= base_url('system/updates/check') ?>" style="display:inline;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" title="Refresh apt metadata and list pending updates">Check for Updates</button>
    </form>
    <form method="POST" action="<?= base_url('system/updates/upgrade') ?>" style="display:inline;"
          onsubmit="return confirm('Upgrade every installed package now? This can take several minutes and may restart core services.');">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary btn-sm" title="Upgrade all installed packages in place (apt-get upgrade)">Upgrade System</button>
    </form>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:18px; margin-bottom:16px;">
  <div style="font-weight:700; font-size:0.95rem; margin-bottom:12px;">Appliance</div>
  <dl class="sysupd-meta">
    <dt>Release</dt><dd><?= esc($os !== '' ? $os : 'MicronOS') ?></dd>
    <dt>Management version</dt><dd><?= esc($version !== '' ? $version : '—') ?></dd>
    <dt>Kernel</dt><dd><?= esc($kernel !== '' ? $kernel : '—') ?></dd>
    <?php if (is_array($report) && ! empty($report['checked_at'])): ?>
      <dt>Last checked</dt><dd><?= esc((string) $report['checked_at']) ?></dd>
    <?php endif; ?>
  </dl>
  <div style="font-size:.74rem; color:var(--text-muted); margin-top:12px; max-width:640px;">
    An upgrade keeps the configured repositories and never removes packages — it is an in-place point
    release, not a distribution upgrade. Plugin packages are managed separately on the
    <a href="<?= base_url('plugins/updates') ?>" style="color:var(--color-cyan,#06b6d4);">Plugin Updates</a> screen.
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:0; overflow:hidden;">
  <div style="padding:14px 16px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
    <div>
      <div style="font-weight:700; font-size:0.9rem;">Available updates</div>
      <div style="font-size:.74rem; color:var(--text-muted); margin-top:2px;">Run a check to refresh this list from the package repositories.</div>
    </div>
    <?php if ($packages !== []): ?>
      <span class="badge badge-warning" style="font-size:0.7rem;"><?= count($packages) ?> PENDING</span>
    <?php endif; ?>
  </div>

  <?php if (! is_array($report)): ?>
    <div style="padding:20px; font-size:.85rem; color:var(--text-muted);">
      No check has been run yet. Click <strong>Check for Updates</strong> to query the repositories.
    </div>
  <?php elseif ($packages === []): ?>
    <div style="padding:20px; font-size:.85rem; color:var(--text-muted);">
      <span class="badge badge-success" style="font-size:.62rem; margin-right:8px;">UP TO DATE</span>
      No package updates are available for this appliance.
    </div>
  <?php else: ?>
    <table class="sysupd-table">
      <thead>
        <tr>
          <th>Package</th>
          <th style="width:220px;">Installed</th>
          <th style="width:220px;">Candidate</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($packages as $pkg): ?>
          <tr>
            <td><?= esc((string) ($pkg['name'] ?? '')) ?></td>
            <td><?= esc((string) ($pkg['current'] ?? '') !== '' ? (string) $pkg['current'] : '—') ?></td>
            <td style="color:var(--color-cyan,#06b6d4);"><?= esc((string) ($pkg['candidate'] ?? '') !== '' ? (string) $pkg['candidate'] : '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?= $this->endSection() ?>
