<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.plug-chip {
  display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:4px; font-family:var(--font-mono);
  font-size:0.7rem; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.12); color:var(--text-secondary); margin:1px 2px 1px 0;
}
.plug-meta { display:grid; grid-template-columns:120px 1fr; gap:6px 14px; font-size:.82rem; }
.plug-meta dt { color:var(--text-muted); font-weight:600; }
.plug-meta dd { margin:0; color:var(--text-primary); }
.plug-cap-table { width:100%; border-collapse:separate; border-spacing:0; font-size:0.8rem; }
.plug-cap-table th { background:var(--color-surface-3); color:var(--text-secondary); font-weight:600; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.05em; padding:8px 12px; border-bottom:1px solid var(--color-border); text-align:left; }
.plug-cap-table td { padding:8px 12px; border-bottom:1px solid var(--color-border); font-family:var(--font-mono); font-size:0.74rem; }
</style>

<div class="page-header animate-in">
  <div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <h1 class="page-title"><?= esc($plugin['name']) ?></h1>
      <span class="badge badge-muted" style="font-size:0.7rem;">CLASS <?= esc($plugin['class']) ?></span>
      <span class="badge badge-info" style="font-size:0.7rem;">v<?= esc($plugin['version']) ?></span>
      <?php if ((int) $enabled === 1): ?>
        <span class="badge badge-success" style="font-size:0.7rem;">ENABLED</span>
      <?php else: ?>
        <span class="badge badge-warning" style="font-size:0.7rem;">INSTALLED</span>
      <?php endif; ?>
    </div>
    <p class="page-subtitle"><?= esc($plugin['description'] !== '' ? $plugin['description'] : 'Installed plugin') ?></p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
    <?php if (! empty($plugin['units'])): ?>
      <?= \App\Libraries\ServiceStatus::pluginWidget(
            [['id' => $plugin['id'], 'name' => $plugin['name'], 'installed' => true, 'units' => $plugin['units']]],
            $states ?? [],
          ) ?>
    <?php endif; ?>
    <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
    <a href="<?= base_url('plugins/updates') ?>" class="btn btn-ghost btn-sm">Updates</a>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:18px; margin-bottom:16px;">
  <div style="font-weight:700; font-size:0.95rem; margin-bottom:12px;">Overview</div>
  <dl class="plug-meta">
    <dt>ID</dt><dd><?= esc($plugin['id']) ?></dd>
    <dt>Vendor</dt><dd><?= esc($plugin['vendor'] !== '' ? $plugin['vendor'] : '—') ?></dd>
    <dt>Installed version</dt><dd><?= esc($installedVersion) ?><?php if (version_compare($plugin['version'], $installedVersion, '>')): ?> → <strong style="color:var(--color-amber,#f59e0b);"><?= esc($plugin['version']) ?> available</strong><?php endif; ?></dd>
    <?php if (! empty($installedAt)): ?><dt>Installed</dt><dd><?= esc((string) $installedAt) ?></dd><?php endif; ?>
    <dt>Packages</dt><dd><?php if ($plugin['packages'] === []): ?>—<?php else: ?><?php foreach ($plugin['packages'] as $pkg): ?><span class="plug-chip"><?= esc($pkg) ?></span><?php endforeach; ?><?php endif; ?></dd>
    <dt>Daemon unit(s)</dt><dd><?php if ($plugin['units'] === []): ?><span style="color:var(--text-muted);">none — no daemon</span><?php else: ?><?php foreach ($plugin['units'] as $unit): ?><span class="plug-chip"><?= esc($unit) ?></span><?php endforeach; ?><?php endif; ?></dd>
    <dt>Menu</dt><dd><?php if ($plugin['menu'] === []): ?>—<?php else: ?><?php foreach ($plugin['menu'] as $m): ?><span class="plug-chip"><?= esc($m['label']) ?> · <?= esc($m['path']) ?></span><?php endforeach; ?><?php endif; ?></dd>
  </dl>

  <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:16px;">
    <form method="POST" action="<?= base_url('plugins/' . esc($plugin['id'], 'raw') . '/upgrade') ?>" style="display:inline;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary btn-sm" title="Upgrade the plugin's packages">Update</button>
    </form>
    <form method="POST" action="<?= base_url('plugins/' . esc($plugin['id'], 'raw') . '/remove') ?>" style="display:inline;"
          onsubmit="return confirm('Remove <?= esc($plugin['name'], 'js') ?>? Its packages will be uninstalled and its data retained.');">
      <?= csrf_field() ?><input type="hidden" name="purge" value="0">
      <button type="submit" class="btn btn-ghost btn-sm" title="Uninstall the plugin (data retained)">Remove</button>
    </form>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:0; overflow:hidden;">
  <div style="padding:14px 16px; border-bottom:1px solid var(--color-border);">
    <div style="font-weight:700; font-size:0.9rem;">Declared capabilities</div>
    <div style="font-size:.74rem; color:var(--text-muted); margin-top:2px;">What the plugin declared, and whether the operator granted it.</div>
  </div>
  <?php if ($grants === []): ?>
    <div style="padding:14px 16px; font-size:.8rem; color:var(--text-muted);">No capabilities were recorded for this plugin.</div>
  <?php else: ?>
    <table class="plug-cap-table">
      <thead><tr><th>Capability</th><th style="width:120px;">Granted</th><th style="width:170px;">Granted by</th></tr></thead>
      <tbody>
        <?php foreach ($grants as $g): ?>
          <tr>
            <td><?= esc((string) $g['capability']) ?></td>
            <td>
              <?php if ((int) $g['granted'] === 1): ?>
                <span class="badge badge-success" style="font-size:.62rem;">GRANTED</span>
              <?php else: ?>
                <span class="badge badge-muted" style="font-size:.62rem;">NOT GRANTED</span>
              <?php endif; ?>
            </td>
            <td style="font-family:inherit;"><?= esc((string) ($g['granted_by'] ?? '—') ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?= $this->endSection() ?>
