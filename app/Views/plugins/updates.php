<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.upd-table { width:100%; border-collapse:separate; border-spacing:0; font-size:0.82rem; }
.upd-table th {
  background:var(--color-surface-3); color:var(--text-secondary); font-weight:600; font-size:0.72rem;
  text-transform:uppercase; letter-spacing:0.05em; padding:10px 14px; border-bottom:1px solid var(--color-border); text-align:left;
}
.upd-table td { padding:10px 14px; border-bottom:1px solid var(--color-border); vertical-align:middle; }
.upd-table tbody tr:hover td { background:rgba(255,255,255,0.02); }
.upd-chip { display:inline-flex; padding:1px 7px; border-radius:4px; font-family:var(--font-mono); font-size:0.7rem; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.12); color:var(--text-secondary); margin:1px 2px 1px 0; }
.upd-meta { display:grid; grid-template-columns:150px 1fr; gap:6px 16px; font-size:0.8rem; }
.upd-meta dt { color:var(--text-muted); font-weight:600; }
.upd-meta dd { margin:0; color:var(--text-primary); font-family:var(--font-mono); font-size:0.74rem; word-break:break-all; }
</style>

<?php $cat = $catalogue ?? ['configured'=>false,'available'=>false,'count'=>0,'stale'=>false]; ?>

<div class="page-header animate-in">
  <div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <h1 class="page-title">Plugin Updates &amp; Upgrades</h1>
      <span class="badge badge-info" style="font-size:0.75rem;"><?= count($plugins) ?> Installed</span>
      <?php if (! empty($cat['available'])): ?>
        <span class="badge badge-success" style="font-size:0.68rem;">CATALOGUE VERIFIED</span>
      <?php endif; ?>
    </div>
    <p class="page-subtitle">Detect newer versions from the signed remote catalogue, then upgrade a single plugin's packages or every installed plugin at once</p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
    <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
    <form method="POST" action="<?= base_url('plugins/catalogue/sync') ?>" style="display:inline;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary btn-sm" title="Fetch and verify the signed plugin catalogue">Check Signed Catalogue</button>
    </form>
    <form method="POST" action="<?= base_url('plugins/refresh') ?>" style="display:inline;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" title="Refresh the curated repository metadata (apt-get update)">Refresh Repositories</button>
    </form>
    <form method="POST" action="<?= base_url('plugins/upgrade-all') ?>" style="display:inline;"
          onsubmit="return confirm('Upgrade every installed plugin now?');">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" <?= $plugins === [] ? 'disabled' : '' ?> title="Upgrade all installed plugins">Upgrade All</button>
    </form>
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:18px; margin-bottom:16px;">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
    <div>
      <div style="font-weight:700; font-size:0.95rem;">Signed remote catalogue</div>
      <div style="font-size:.74rem; color:var(--text-muted); margin-top:2px;">
        Newer plugin versions are read from a catalogue signed by a key shipped in the appliance image. An unsigned, tampered or expired document is refused and never cached.
      </div>
    </div>
    <?php if (! empty($cat['stale'])): ?>
      <span class="badge badge-warning" style="font-size:0.68rem;">STALE</span>
    <?php endif; ?>
  </div>

  <?php if (empty($cat['configured'])): ?>
    <div style="font-size:.82rem; color:var(--text-muted);">
      The signed catalogue is <strong>not configured</strong> on this appliance. Set a catalogue URL and a trusted
      public key (keyring or config), then use <strong>Check Signed Catalogue</strong>. Until then, versions are compared against the shipped manifests only.
    </div>
  <?php elseif (empty($cat['available'])): ?>
    <div style="font-size:.82rem; color:var(--text-muted);">
      <?php if (! empty($cat['error'])): ?>
        <span class="badge badge-warning" style="font-size:.62rem; margin-right:6px;">NOT VERIFIED</span><?= esc((string) $cat['error']) ?>
      <?php else: ?>
        No catalogue has been fetched yet. Use <strong>Check Signed Catalogue</strong> to fetch and verify it.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <dl class="upd-meta">
      <dt>Feed version</dt><dd><?= $cat['version'] !== null ? (int) $cat['version'] : '—' ?> · <?= (int) $cat['count'] ?> plugin(s)</dd>
      <dt>Source URL</dt><dd><?= esc((string) ($cat['url'] ?? '')) ?></dd>
      <dt>Signing key</dt><dd><?= esc((string) ($cat['key_id'] ?? '—')) ?></dd>
      <dt>Issued / expires</dt><dd><?= esc((string) ($cat['issued_at'] ?? '—')) ?> → <?= esc((string) ($cat['expires_at'] ?? '—')) ?></dd>
      <dt>Fetched</dt><dd><?= esc((string) ($cat['fetched_at'] ?? '—')) ?></dd>
      <dt>SHA-256</dt><dd><?= esc(substr((string) ($cat['sha256'] ?? ''), 0, 32)) ?>…</dd>
    </dl>
  <?php endif; ?>
</div>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:0; overflow:hidden;">
  <?php if ($plugins === []): ?>
    <div style="padding:20px; font-size:.85rem; color:var(--text-muted);">
      No plugins are installed. Install one from <a href="<?= base_url('plugins') ?>" style="color:var(--color-cyan,#06b6d4);">Plugins &amp; Packages</a>.
    </div>
  <?php else: ?>
    <table class="upd-table">
      <thead>
        <tr>
          <th>Plugin</th>
          <th style="width:120px;">Installed</th>
          <th style="width:170px;">Available</th>
          <th style="width:150px;">State</th>
          <th style="width:150px; text-align:right;"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($plugins as $p): ?>
          <tr>
            <td>
              <a href="<?= base_url('plugins/' . esc($p['id'], 'raw')) ?>" style="color:var(--text-primary); font-weight:600; text-decoration:none;"><?= esc($p['name']) ?></a>
              <div style="font-size:.72rem; color:var(--text-muted); margin-top:2px;">
                <?php if ($p['packages'] === []): ?><span>no packages</span><?php else: ?><?php foreach ($p['packages'] as $pkg): ?><span class="upd-chip"><?= esc($pkg) ?></span><?php endforeach; ?><?php endif; ?>
              </div>
            </td>
            <td><?= esc($p['installed_version'] !== '' ? $p['installed_version'] : '—') ?></td>
            <td>
              <?= esc($p['available_version']) ?>
              <?php if ($p['source'] === 'remote'): ?>
                <div style="margin-top:3px;"><span class="badge badge-info" style="font-size:.58rem;">FROM CATALOGUE</span></div>
              <?php else: ?>
                <div style="margin-top:3px;"><span class="badge badge-muted" style="font-size:.58rem;">SHIPPED</span></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($p['update_available']): ?>
                <span class="badge badge-warning" style="font-size:.62rem;">UPDATE AVAILABLE</span>
              <?php else: ?>
                <span class="badge badge-success" style="font-size:.62rem;">UP TO DATE</span>
              <?php endif; ?>
              <?php if ((int) $p['enabled'] !== 1): ?>
                <span class="badge badge-muted" style="font-size:.62rem;">DISABLED</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right; white-space:nowrap;">
              <form method="POST" action="<?= base_url('plugins/' . esc($p['id'], 'raw') . '/upgrade') ?>" style="display:inline;">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" title="Upgrade this plugin's packages">Upgrade</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?= $this->endSection() ?>
