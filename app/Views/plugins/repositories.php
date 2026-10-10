<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.repo-table { width:100%; border-collapse:separate; border-spacing:0; font-size:0.82rem; }
.repo-table th {
  background:var(--color-surface-3); color:var(--text-secondary); font-weight:600; font-size:0.72rem;
  text-transform:uppercase; letter-spacing:0.05em; padding:10px 14px; border-bottom:1px solid var(--color-border); text-align:left;
}
.repo-table td { padding:10px 14px; border-bottom:1px solid var(--color-border); vertical-align:middle; }
.repo-table tbody tr:hover td { background:rgba(255,255,255,0.02); }
.repo-chip { display:inline-flex; padding:1px 7px; border-radius:4px; font-family:var(--font-mono); font-size:0.7rem; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.12); color:var(--text-secondary); margin:1px 2px 1px 0; }
.repo-note { border-radius:8px; padding:10px 12px; margin-bottom:10px; font-size:.8rem; }
.repo-note.err  { background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.3); color:#fca5a5; }
.repo-note.warn { background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.3); color:#fcd34d; }
.repo-note.info { background:rgba(56,189,248,.08); border:1px solid rgba(56,189,248,.25); color:#7dd3fc; }
.repo-drift { border-left:3px solid rgba(255,255,255,.12); padding:8px 0 8px 12px; margin-top:10px; }
.repo-drift.critical { border-left-color:rgba(239,68,68,.7); }
.repo-drift.warning  { border-left-color:rgba(245,158,11,.7); }
.repo-drift-kind { display:inline-flex; padding:1px 7px; border-radius:4px; font-size:.66rem; font-weight:600; margin:1px 3px 1px 0;
  text-transform:uppercase; letter-spacing:.04em; }
.repo-drift-kind.critical { background:rgba(239,68,68,.16); border:1px solid rgba(239,68,68,.4); color:#fca5a5; }
.repo-drift-kind.warning  { background:rgba(245,158,11,.14); border:1px solid rgba(245,158,11,.36); color:#fcd34d; }
.repo-drift-why { font-size:.72rem; color:var(--text-secondary); margin-top:4px; }
.repo-drift-fix { font-size:.7rem; color:var(--text-muted); margin-top:2px; }
.repo-field label { display:block; font-size:0.7rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:4px; }
.repo-field input, .repo-field select, .repo-field textarea {
  background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary);
  padding:6px 10px; border-radius:var(--radius-sm); font-size:0.82rem; width:100%;
}
.repo-field textarea { font-family:var(--font-mono); font-size:0.72rem; }
.repo-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; }
.repo-stanza { font-family:var(--font-mono); font-size:0.7rem; white-space:pre-wrap; word-break:break-all; background:rgba(0,0,0,.25); border:1px solid var(--color-border); border-radius:6px; padding:8px 10px; color:var(--text-secondary); margin:0; }
.repo-meta { display:grid; grid-template-columns:150px 1fr; gap:6px 16px; font-size:0.8rem; margin:0; }
.repo-meta dt { color:var(--text-muted); font-weight:600; }
.repo-meta dd { margin:0; color:var(--text-primary); font-family:var(--font-mono); font-size:0.74rem; word-break:break-all; }
@media (max-width: 900px) { .repo-grid { grid-template-columns:1fr; } }
</style>

<?php
$plan       = $plan ?? ['repos' => [], 'conflicts' => [], 'warnings' => [], 'ready' => true, 'registered' => 0, 'enabled' => 0];
$daemon     = $daemon ?? [];
$edit       = $edit ?? null;
$orphans    = $orphans ?? [];
$testReport = $testReport ?? null;
$presets    = $presets ?? [];
$drift      = $drift ?? ['flagged' => 0, 'critical' => 0, 'highest' => ''];
$driftKinds = $driftKinds ?? [];
$driftWindow = $driftWindow ?? 14;
$driftSchedule = $driftSchedule ?? null;
$appliedCount = 0;
foreach ($repositories as $__r) { if (! empty($__r['applied'])) { $appliedCount++; } }
$flagged = [];
foreach ($repositories as $__r) { if (($__r['drift_kinds'] ?? []) !== []) { $flagged[] = $__r; } }
?>

<div class="page-header animate-in">
  <div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <h1 class="page-title">Plugin Repositories</h1>
      <span class="badge badge-info" style="font-size:0.75rem;"><?= count($repositories) ?> Registered</span>
      <?php if ($plan['enabled'] > 0): ?>
        <span class="badge badge-muted" style="font-size:0.68rem;"><?= (int) $plan['enabled'] ?> Enabled</span>
      <?php endif; ?>
      <?php if ($appliedCount > 0): ?>
        <span class="badge badge-success" style="font-size:0.68rem;"><?= $appliedCount ?> Applied on daemon</span>
      <?php endif; ?>
      <?php if (! empty($daemon['core_pinned'])): ?>
        <span class="badge badge-success" style="font-size:0.66rem;">CORE PINNED</span>
      <?php endif; ?>
      <?php if ((int) $drift['flagged'] > 0): ?>
        <span class="badge badge-<?= (string) $drift['highest'] === 'critical' ? 'danger' : 'warning' ?>" style="font-size:0.68rem;"
              title="Repositories whose metadata or signing key has drifted — see the drift panel below">
          <?= (int) $drift['flagged'] ?> DRIFTING<?= (int) $drift['critical'] > 0 ? ' · ' . (int) $drift['critical'] . ' CRITICAL' : '' ?>
        </span>
      <?php endif; ?>
    </div>
    <p class="page-subtitle">Curated, signed apt repositories the plugin packages are installed from — generated sources, fingerprint-verified keys and a pin that keeps every one of them below the distribution</p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
    <a href="<?= base_url('plugins') ?>" class="btn btn-ghost btn-sm">All Plugins</a>
    <a href="<?= base_url('plugins/updates') ?>" class="btn btn-ghost btn-sm">Updates &amp; Upgrades</a>
    <form method="POST" action="<?= base_url('plugins/repositories/refresh') ?>" style="display:inline;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" title="apt-get update against the applied sources only">Refresh Metadata</button>
    </form>
    <form method="POST" action="<?= base_url('plugins/repositories/drift') ?>" style="display:inline;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm"
              title="Refresh metadata and flag drift now — stale or expired metadata, keys expiring or rotated, repositories that stopped publishing">Check Drift Now</button>
    </form>
    <form method="POST" action="<?= base_url('plugins/repositories/apply') ?>" style="display:inline;"
          onsubmit="return confirm('Reconcile the curated repositories on this appliance now?');">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary btn-sm" <?= $plan['ready'] ? '' : 'disabled' ?>>Apply Repositories</button>
    </form>
  </div>
</div>

<?php if (! empty($plan['conflicts'])): ?>
  <div class="repo-note err animate-in">
    <strong>Cannot apply:</strong>
    <ul style="margin:6px 0 0 18px;">
      <?php foreach ($plan['conflicts'] as $conflict): ?><li><?= esc($conflict) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php foreach ($plan['warnings'] as $warning): ?>
  <div class="repo-note warn animate-in">⚠ <?= esc($warning) ?></div>
<?php endforeach; ?>

<?php // ---- Repository drift (§5.2) -------------------------------------------
// Health, as opposed to trustworthiness. The verdict is carried on each row
// because it is a comparison between sweeps, not a property of one reading.
$scheduleLine = 'The scheduled drift sweep is not present — nothing is observing these repositories between manual checks.';
$scheduleState = 'warn';
if (is_array($driftSchedule)) {
    $cadence = sprintf('%s:%s daily', str_pad((string) $driftSchedule['hour'], 2, '0', STR_PAD_LEFT), str_pad((string) $driftSchedule['minute'], 2, '0', STR_PAD_LEFT));
    if ((int) ($driftSchedule['enabled'] ?? 0) !== 1) {
        $scheduleLine = 'The drift sweep (' . $cadence . ') is scheduled but disabled.';
    } elseif (empty($driftSchedule['last_run_at'])) {
        $scheduleLine = 'The drift sweep has not run yet (scheduled ' . $cadence . ').';
    } else {
        $scheduleLine = 'Drift sweep scheduled ' . $cadence . ' · last recorded run ' . (string) $driftSchedule['last_run_at']
            . ' (' . (string) ($driftSchedule['last_status'] ?? 'unknown') . ').';
        $scheduleState = 'info';
    }
}
?>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:16px 18px; margin-bottom:16px;">
  <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:4px;">
    <div style="font-weight:700; font-size:0.95rem;">Repository drift</div>
    <?php if ((int) $drift['flagged'] === 0): ?>
      <span class="badge badge-success" style="font-size:.66rem;">NO DRIFT FLAGGED</span>
    <?php else: ?>
      <span class="badge badge-<?= (string) $drift['highest'] === 'critical' ? 'danger' : 'warning' ?>" style="font-size:.66rem;">
        <?= (int) $drift['flagged'] ?> FLAGGED
      </span>
    <?php endif; ?>
    <span class="badge badge-muted" style="font-size:.62rem;">OBSERVED, NOT GUESSED</span>
  </div>
  <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:10px;">
    A curated repository can be configured perfectly and still be quietly dead: the vendor stops publishing, its signing
    key reaches the end of its own validity, or it rotates the key so the metadata no longer verifies. None of that is
    visible from a single check — each one is a comparison against what the previous sweep saw, which is why the sweep is
    scheduled and its verdict is kept on the repository. Refreshing metadata and observing drift are both read-only:
    nothing in this panel can change what apt may install. Each sweep also <strong>re-verifies</strong> every repository, so one
    that verified before and no longer does is flagged <strong>Verification lost</strong>, with the reason.
  </div>

  <div class="repo-note <?= esc($scheduleState) ?>" style="margin-bottom:12px;">
    <?= esc($scheduleLine) ?>
    <a href="<?= base_url('cron') ?>" style="margin-left:6px;">Scheduled tasks →</a>
  </div>

  <?php if ($flagged === []): ?>
    <div style="font-size:.78rem; color:var(--text-muted);">
      <?php if ($repositories === []): ?>
        No repositories are registered, so there is nothing to observe.
      <?php else: ?>
        Every registered repository is within its thresholds. Repositories that are disabled are still shown above but are
        not swept — the sweep observes the repositories apt is actually using.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php foreach ($flagged as $repo): ?>
      <?php $severity = \App\Libraries\RepoDrift::highest((array) $repo['drift_kinds']); ?>
      <div class="repo-drift <?= esc($severity) ?>">
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
          <span style="font-weight:600; font-size:.84rem;"><?= esc($repo['name']) ?></span>
          <span class="repo-chip"><?= esc($repo['id']) ?></span>
          <?php foreach ((array) $repo['drift_kinds'] as $kind): ?>
            <span class="repo-drift-kind <?= esc(\App\Libraries\RepoDrift::severityOf((string) $kind)) ?>"
                  title="<?= esc(\App\Libraries\RepoDrift::remedyOf((string) $kind)) ?>">
              <?= esc(\App\Libraries\RepoDrift::labelOf((string) $kind)) ?>
            </span>
          <?php endforeach; ?>
          <?php if (! empty($repo['drift_since'])): ?>
            <span style="font-size:.68rem; color:var(--text-muted);">flagged since <?= esc((string) $repo['drift_since']) ?></span>
          <?php endif; ?>
        </div>

        <?php foreach (preg_split('/\r?\n/', (string) $repo['drift_detail']) ?: [] as $line): ?>
          <?php if (trim((string) $line) !== ''): ?><div class="repo-drift-why"><?= esc((string) $line) ?></div><?php endif; ?>
        <?php endforeach; ?>

        <div class="repo-drift-fix">
          <?php foreach ((array) $repo['drift_kinds'] as $kind): ?>
            <div>· &ldquo;<?= esc(\App\Libraries\RepoDrift::labelOf((string) $kind)) ?>&rdquo; — <?= esc(\App\Libraries\RepoDrift::remedyOf((string) $kind)) ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if ($orphans !== []): ?>
  <div class="repo-note warn animate-in">
    ⚠ On the daemon but no longer enabled here:
    <?php foreach ($orphans as $orphan): ?><span class="repo-chip"><?= esc((string) ($orphan['id'] ?? '')) ?></span><?php endforeach; ?>
    — applying the repositories removes the files MicronOS generated for them.
  </div>
<?php endif; ?>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:16px 18px; margin-bottom:16px;">
  <div style="font-weight:700; font-size:0.95rem; margin-bottom:6px;">How a curated repository is applied</div>
  <ol style="font-size:.8rem; color:var(--text-secondary); margin:0 0 10px 18px; line-height:1.6;">
    <li>The daemon <strong>generates</strong> <code>/etc/apt/sources.list.d/micron-&lt;id&gt;.sources</code> from the fields below. There is no field for apt options, so <code>[trusted=yes]</code> and similar cannot be expressed, and only <code>deb</code> (never <code>deb-src</code>) is written.</li>
    <li>The signing key is imported into a private keyring and its <strong>fingerprint must equal</strong> the one you declare here. A mismatch refuses the repository — the fingerprint must come from the vendor out of band, never from the same URL as the key.</li>
    <li>The repository is pinned to priority <strong><?= (int) $pinPriority ?></strong>, below the distribution's own 500, so a package the distribution already ships is always taken from the distribution.</li>
    <li>The protected core packages are pinned to the appliance's own release, so no curated repository can supply — let alone upgrade — <code>nftables</code>, <code>unbound</code>, <code>kea-dhcp4-server</code> or any other core dependency.</li>
    <li>Applying is declarative: a repository you disable or delete has the files MicronOS generated for it removed. Nothing outside the <code>micron-</code> namespace is ever touched.</li>
  </ol>
  <div style="font-size:.74rem; color:var(--text-muted);">
    <?php if (! empty($daemon['core_pinned'])): ?>
      Core pin active: <code><?= esc((string) ($daemon['core_pin'] ?? '')) ?></code>
    <?php else: ?>
      Core pin not written yet — apply the repositories to install it.
    <?php endif; ?>
  </div>
</div>

<?php if (is_array($testReport) && ! empty($testReport['id'])): ?>
  <?php
  $verified = ! empty($testReport['ok']);
  $release  = (array) ($testReport['release'] ?? []);
  $signature = (array) ($testReport['signature'] ?? []);
  $key      = (array) ($testReport['key'] ?? []);
  // A preset report is about the image's shipped trust data, not a stored row,
  // so the panel says which it is rather than presenting them as the same thing.
  $reportPreset = (string) ($testReport['preset'] ?? '');
  $isPreset     = $reportPreset !== '';
  ?>
  <div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px; margin-bottom:16px;
       border-color:<?= $verified ? 'rgba(34,197,94,.4)' : 'rgba(239,68,68,.4)' ?>;">
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:4px;">
      <div style="font-weight:700; font-size:0.95rem;"><?= $isPreset ? 'Preset verification — ' . esc((string) ($testReport['preset_name'] ?? $reportPreset)) : 'Repository verification — ' . esc((string) $testReport['id']) ?></div>
      <?php if ($verified): ?>
        <span class="badge badge-success" style="font-size:.66rem;">VERIFIED</span>
      <?php else: ?>
        <span class="badge badge-danger" style="font-size:.66rem;">FAILED</span>
      <?php endif; ?>
      <span class="badge badge-muted" style="font-size:.62rem;">NOTHING WAS WRITTEN</span>
      <?php if ($isPreset): ?>
        <span class="badge badge-info" style="font-size:.62rem;">IMAGE-SHIPPED TRUST DATA</span>
      <?php endif; ?>
      <?php if ((string) ($testReport['checked_at'] ?? '') !== ''): ?>
        <span style="font-size:.68rem; color:var(--text-muted);">checked <?= esc((string) $testReport['checked_at']) ?></span>
      <?php endif; ?>
      <form method="POST" action="<?= base_url('plugins/repositories/test/clear') ?>" style="display:inline; margin-left:auto;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 8px; font-size:.7rem;"
                title="Hide this verification report">Dismiss</button>
      </form>
    </div>
    <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:12px;">
      The release metadata was fetched, verified against a keyring built from the repository's signing key, and the signer was compared to the declared fingerprint. No source, pin, keyring or apt list was written and no package operation ran.
    </div>
    <?php if ($isPreset): ?>
      <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:12px;">
        This checked the preset's <strong>image-shipped</strong> trust data — the declared fingerprint
        <?= esc((string) ($key['declared'] ?? '')) ?>, key re-derived from <?= esc((string) ($testReport['preset_key_source'] ?? '')) ?>
        <?php if ((string) ($testReport['preset_verified_at'] ?? '') !== ''): ?>(the image records it as verified <?= esc((string) $testReport['preset_verified_at']) ?>)<?php endif; ?> —
        and not any repository you have registered, which may deliberately pin a different key.
        <?= esc((string) ($testReport['preset_url'] ?? '')) ?> was not registered, enabled or applied.
      </div>
    <?php endif; ?>

    <?php if (! $verified): ?>
      <div class="repo-note err" style="margin-bottom:12px;"><?= esc((string) ($testReport['message'] ?? 'verification failed')) ?></div>
    <?php else: ?>
      <dl class="repo-meta">
        <dt>Source</dt><dd><?= esc((string) ($testReport['release_url'] ?? '')) ?> (<?= esc((string) ($testReport['release_mode'] ?? '')) ?>)</dd>
        <dt>Declared key</dt><dd><?= esc((string) ($key['declared'] ?? '')) ?></dd>
        <dt>Keyring held</dt>
        <dd><?php if ((array) ($key['found'] ?? []) === []): ?>—<?php else: ?><?php foreach ((array) $key['found'] as $foundKey): ?><span class="repo-chip">…<?= esc(substr((string) $foundKey, -16)) ?></span><?php endforeach; ?><?php endif; ?></dd>
        <dt>Signature</dt>
        <dd>
          VERIFIED · <?= esc((string) ($signature['mode'] ?? '')) ?> · signer …<?= esc(substr((string) ($signature['signer'] ?? ''), -16)) ?>
        </dd>
        <dt>Release origin</dt><dd><?= esc((string) ($release['origin'] ?? '—')) ?><?= ($release['label'] ?? '') !== '' ? ' · ' . esc((string) $release['label']) : '' ?></dd>
        <dt>Suite / codename</dt><dd><?= esc((string) ($release['suite'] ?? '—')) ?> · <?= esc((string) ($release['codename'] ?? '—')) ?></dd>
        <dt>Architectures</dt><dd><?= esc(implode(' ', (array) ($release['architectures'] ?? []))) ?: '—' ?></dd>
        <dt>Components</dt><dd><?= esc(implode(' ', (array) ($release['components'] ?? []))) ?: '—' ?></dd>
        <?php if (! empty($release['valid_until'])): ?>
          <dt>Valid until</dt><dd><?= esc((string) $release['valid_until']) ?></dd>
        <?php endif; ?>
      </dl>
    <?php endif; ?>

    <?php foreach ((array) ($testReport['warnings'] ?? []) as $warning): ?>
      <div class="repo-note warn" style="margin-top:8px; margin-bottom:0;">⚠ <?= esc((string) $warning) ?></div>
    <?php endforeach; ?>

    <?php if ((array) ($testReport['steps'] ?? []) !== []): ?>
      <div style="margin-top:10px; font-size:.72rem; color:var(--text-muted); font-family:var(--font-mono);">
        <?php foreach ((array) $testReport['steps'] as $step): ?><div>· <?= esc((string) $step) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:0; overflow:hidden; margin-bottom:16px;">
  <?php if ($repositories === []): ?>
    <div style="padding:20px; font-size:.85rem; color:var(--text-muted);">
      No curated repositories are registered. A plugin that requires one (its manifest's <code>requires.repos</code>) cannot be installed until it is added below.
    </div>
  <?php else: ?>
    <table class="repo-table">
      <thead>
        <tr>
          <th>Repository</th>
          <th style="width:230px;">Source</th>
          <th style="width:190px;">Signing key</th>
          <th style="width:190px;">State</th>
          <th style="width:200px;">Drift</th>
          <th style="width:120px;">Last apply</th>
          <th style="width:190px; text-align:right;"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($repositories as $repo): ?>
          <tr>
            <td>
              <div style="font-weight:600; color:var(--text-primary);"><?= esc($repo['name']) ?></div>
              <div style="font-size:.72rem; color:var(--text-muted); font-family:var(--font-mono); margin-top:2px;"><?= esc($repo['id']) ?></div>
            </td>
            <td>
              <div style="font-family:var(--font-mono); font-size:.72rem; word-break:break-all;"><?= esc($repo['url']) ?></div>
              <div style="margin-top:4px;">
                <span class="repo-chip"><?= esc($repo['suite']) ?></span>
                <?php foreach ($repo['components'] as $component): ?><span class="repo-chip"><?= esc($component) ?></span><?php endforeach; ?>
                <?php foreach ($repo['architectures'] as $architecture): ?><span class="repo-chip"><?= esc($architecture) ?></span><?php endforeach; ?>
              </div>
            </td>
            <td>
              <?php if ($repo['fingerprint'] !== ''): ?>
                <div style="font-family:var(--font-mono); font-size:.7rem; word-break:break-all;" title="<?= esc($repo['fingerprint']) ?>">…<?= esc(substr($repo['fingerprint'], -16)) ?></div>
              <?php else: ?>
                <span class="badge badge-danger" style="font-size:.62rem;">NO FINGERPRINT</span>
              <?php endif; ?>
              <div style="margin-top:4px;">
                <?php if ($repo['key_armor'] !== ''): ?>
                  <span class="badge badge-muted" style="font-size:.58rem;">KEY STORED</span>
                <?php elseif ($repo['key_url'] !== ''): ?>
                  <span class="badge badge-info" style="font-size:.58rem;">FETCHED PER APPLY</span>
                <?php else: ?>
                  <span class="badge badge-danger" style="font-size:.58rem;">NO KEY</span>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <?php if (is_array($testReport) && ($testReport['id'] ?? '') === $repo['id']): ?>
                <?php if (! empty($testReport['ok'])): ?>
                  <span class="badge badge-success" style="font-size:0.62rem;">TESTED</span>
                <?php else: ?>
                  <span class="badge badge-danger" style="font-size:0.62rem;">TEST FAILED</span>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($repo['enabled']): ?>
                <span class="badge badge-success" style="font-size:0.62rem;">ENABLED</span>
                <?php if (! empty($repo['applied'])): ?>
                  <span class="badge badge-info" style="font-size:0.62rem;">APPLIED</span>
                <?php else: ?>
                  <span class="badge badge-warning" style="font-size:0.62rem;">NOT APPLIED</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="badge badge-muted" style="font-size:0.62rem;">DISABLED</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (($repo['drift_kinds'] ?? []) === []): ?>
                <?php if (empty($repo['last_check_at'])): ?>
                  <span class="badge badge-muted" style="font-size:.62rem;">NOT CHECKED</span>
                <?php else: ?>
                  <span class="badge badge-success" style="font-size:.62rem;">HEALTHY</span>
                <?php endif; ?>
              <?php else: ?>
                <div>
                  <?php foreach ((array) $repo['drift_kinds'] as $kind): ?>
                    <span class="repo-drift-kind <?= esc(\App\Libraries\RepoDrift::severityOf((string) $kind)) ?>"
                          title="<?= esc(\App\Libraries\RepoDrift::remedyOf((string) $kind)) ?>">
                      <?= esc(\App\Libraries\RepoDrift::labelOf((string) $kind)) ?>
                    </span>
                  <?php endforeach; ?>
                </div>
                <?php if (! empty($repo['drift_since'])): ?>
                  <div style="font-size:.66rem; color:var(--text-muted); margin-top:2px;">since <?= esc((string) $repo['drift_since']) ?></div>
                <?php endif; ?>
              <?php endif; ?>
              <?php if (! empty($repo['last_check_at'])): ?>
                <div style="font-size:.66rem; color:var(--text-muted); margin-top:2px;">
                  checked <?= esc((string) $repo['last_check_at']) ?>
                  <?php if (! empty($repo['key_expires_at'])): ?>· key to <?= esc(date('Y-m-d', strtotime((string) $repo['key_expires_at']))) ?><?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if (! empty($repo['last_verified_at'])): ?>
                <div style="font-size:.66rem; color:var(--text-muted); margin-top:2px;" title="The last time this repository's verification succeeded — a later failure is then reported as Verification lost">verified <?= esc((string) $repo['last_verified_at']) ?></div>
              <?php endif; ?>
            </td>
            <td style="font-size:.72rem; color:var(--text-muted);">
              <?php if ($repo['last_outcome'] === 'ok'): ?>
                <span style="color:#4ade80;">ok</span> · <?= esc((string) $repo['last_refresh']) ?>
              <?php elseif ($repo['last_outcome'] === 'error'): ?>
                <span style="color:#fca5a5;">failed</span>
                <div style="font-size:.68rem; margin-top:2px;"><?= esc($repo['last_detail']) ?></div>
              <?php else: ?>
                never
              <?php endif; ?>
            </td>
            <td style="text-align:right; white-space:nowrap;">
              <form method="POST" action="<?= base_url('plugins/repositories/' . esc($repo['id'], 'raw') . '/test') ?>" style="display:inline;">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 8px; font-size:.72rem;"
                        title="Verify the source is reachable and the release is signed by the declared key — nothing is changed">Test</button>
              </form>
              <a href="<?= base_url('plugins/repositories?edit=' . esc($repo['id'], 'url')) ?>" class="btn btn-ghost btn-sm" style="padding:3px 8px; font-size:.72rem;">Edit</a>
              <form method="POST" action="<?= base_url('plugins/repositories/' . esc($repo['id'], 'raw') . '/toggle') ?>" style="display:inline;">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 8px; font-size:.72rem;"
                        title="<?= $repo['enabled'] ? 'Disable — the source is retired on the next apply' : 'Enable — the source is verified first' ?>"><?= $repo['enabled'] ? 'Disable' : 'Enable' ?></button>
              </form>
              <?php if (! $repo['enabled']): ?>
                <form method="POST" action="<?= base_url('plugins/repositories/' . esc($repo['id'], 'raw') . '/toggle') ?>" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="skip_verify" value="1">
                  <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 8px; font-size:.72rem;"
                          title="Enable without verifying (deliberately offline)">Enable offline</button>
                </form>
              <?php endif; ?>
              <form method="POST" action="<?= base_url('plugins/repositories/' . esc($repo['id'], 'raw') . '/delete') ?>" style="display:inline;"
                    onsubmit="return confirm('Remove repository <?= esc($repo['id'], 'js') ?>?');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 8px; font-size:.72rem;">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($presets !== []): ?>
<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px; margin-bottom:16px;">
  <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:4px;">
    <div style="font-weight:700; font-size:0.92rem;">Preset repositories</div>
    <span class="badge badge-info" style="font-size:.62rem;">IMAGE-SHIPPED TRUST DATA</span>
  </div>
  <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:12px;">
    A plugin that installs from a vendor's apt repository cannot be installed without it. These presets carry the
    vendor's published key and the fingerprint of the key that signs its metadata, so adopting one does not depend on
    reading a 40-character fingerprint correctly off a web page. Registering is not trusting: a preset lands
    <strong>disabled</strong>, and nothing is fetched from the vendor until you enable it and apply.
  </div>

  <?php foreach ($presets as $preset): ?>
    <div style="border:1px solid var(--color-border); border-radius:10px; padding:12px 14px; margin-bottom:10px;">
      <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:6px;">
        <span style="font-weight:600; font-size:.84rem;"><?= esc((string) $preset['name']) ?></span>
        <?php if (! empty($preset['registered'])): ?>
          <span class="badge badge-muted" style="font-size:.6rem;">REGISTERED</span>
          <span class="badge badge-<?= ! empty($preset['enabled']) ? 'success' : 'warning' ?>" style="font-size:.6rem;"><?= ! empty($preset['enabled']) ? 'ENABLED' : 'DISABLED' ?></span>
        <?php else: ?>
          <span class="badge badge-info" style="font-size:.6rem;">NOT REGISTERED</span>
        <?php endif; ?>
        <?php foreach ((array) $preset['plugins'] as $pluginId): ?>
          <span class="repo-chip">needed by <?= esc((string) $pluginId) ?></span>
        <?php endforeach; ?>
        <?php if (is_array($testReport) && (string) ($testReport['preset'] ?? '') === $preset['id']): ?>
          <?php // The last check of *this* preset, right where the operator is looking. ?>
          <span class="badge badge-<?= ! empty($testReport['ok']) ? 'success' : 'danger' ?>" style="font-size:.6rem;"
                title="The last check of this preset against its vendor"><?= ! empty($testReport['ok']) ? 'VERIFIED' : 'FAILED' ?></span>
        <?php endif; ?>
        <span style="display:inline-flex; gap:6px; align-items:center; margin-left:auto;">
          <form method="POST" action="<?= base_url('plugins/repositories/preset/' . esc($preset['id'], 'raw') . '/test') ?>" style="display:inline;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 8px; font-size:.72rem;"
                    title="Fetch this vendor's release metadata over the network and prove it is signed by the fingerprint above — nothing is registered, enabled or changed">Verify against vendor</button>
          </form>
          <?php if (! empty($preset['registered'])): ?>
            <form method="POST" action="<?= base_url('plugins/repositories/preset/' . esc($preset['id'], 'raw')) ?>" style="display:inline;">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 8px; font-size:.72rem;"
                      title="Register the preset (your stored fingerprint is left untouched)">Re-register</button>
            </form>
          <?php else: ?>
            <form method="POST" action="<?= base_url('plugins/repositories/preset/' . esc($preset['id'], 'raw')) ?>" style="display:inline;">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-primary btn-sm" style="padding:3px 8px; font-size:.72rem;"
                      title="Register this repository from the image's verified trust data, disabled">Register preset</button>
            </form>
          <?php endif; ?>
        </span>
      </div>

      <dl class="repo-meta" style="grid-template-columns:150px 1fr; display:grid; gap:2px 12px; font-size:.72rem;">
        <dt>Source</dt><dd><?= esc((string) $preset['url']) ?></dd>
        <dt>Suite / components</dt><dd><?= esc((string) $preset['suite']) ?> · <?= esc(implode(' ', (array) $preset['components'])) ?></dd>
        <dt>Architectures</dt><dd><?= esc(implode(' ', (array) $preset['architectures'])) ?></dd>
        <dt>Declared key</dt><dd><?= esc((string) $preset['fingerprint']) ?></dd>
        <dt>Fingerprint verified</dt><dd><?= esc((string) $preset['verified_at']) ?> against <?= esc((string) $preset['key_source_url']) ?></dd>
      </dl>

      <div class="repo-note" style="margin-top:8px; margin-bottom:0; font-size:.7rem;">
        <?= esc((string) $preset['note']) ?>
        <a href="<?= esc((string) $preset['docs'], 'attr') ?>" target="_blank" rel="noopener" style="margin-left:6px;">vendor docs ↗</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px; margin-bottom:16px;">
  <div style="font-weight:700; font-size:0.9rem; margin-bottom:2px;"><?= $edit !== null ? 'Edit repository — ' . esc($edit['id']) : 'Add a repository' ?></div>
  <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:12px;">
    At most <?= (int) $maxRepos ?> repositories. The signing-key fingerprint is the trust anchor: copy it from the vendor's published documentation, not from the key file. Saving <strong>verifies first</strong> — the release metadata is fetched and the signing key checked against that fingerprint — and refuses a source that cannot be reached or does not match.
  </div>
  <form method="POST" action="<?= base_url('plugins/repositories/store') ?>">
    <?= csrf_field() ?>
    <div class="repo-grid">
      <div class="repo-field">
        <label>Id</label>
        <input type="text" name="id" value="<?= esc((string) ($edit['id'] ?? '')) ?>" placeholder="zerotier" required
               <?= $edit !== null ? 'readonly' : '' ?> pattern="[a-z0-9][a-z0-9-]{0,31}">
      </div>
      <div class="repo-field">
        <label>Display name</label>
        <input type="text" name="name" value="<?= esc((string) ($edit['name'] ?? '')) ?>" placeholder="ZeroTier One" maxlength="128">
      </div>
      <div class="repo-field">
        <label>Signing key fingerprint</label>
        <input type="text" name="key_fingerprint" value="<?= esc((string) ($edit['fingerprint'] ?? '')) ?>"
               placeholder="ABCD1234… (40 or 64 hex)" required>
      </div>
    </div>

    <div style="height:12px;"></div>

    <div class="repo-grid">
      <div class="repo-field">
        <label>Repository URL</label>
        <input type="text" name="url" value="<?= esc((string) ($edit['url'] ?? '')) ?>" placeholder="https://download.example.com/debian" required>
      </div>
      <div class="repo-field">
        <label>Suite <span style="text-transform:none; font-weight:400;">(use ./ for a flat repository)</span></label>
        <input type="text" name="suite" value="<?= esc((string) ($edit['suite'] ?? '')) ?>" placeholder="stable" required>
      </div>
      <div class="repo-field">
        <label>Components <span style="text-transform:none; font-weight:400;">(comma separated)</span></label>
        <input type="text" name="components" value="<?= esc(implode(', ', (array) ($edit['components'] ?? []))) ?>" placeholder="main">
      </div>
    </div>

    <div style="height:12px;"></div>

    <div class="repo-grid">
      <div class="repo-field">
        <label>Architectures <span style="text-transform:none; font-weight:400;">(comma separated)</span></label>
        <input type="text" name="architectures" value="<?= esc(implode(', ', (array) ($edit['architectures'] ?? []))) ?>" placeholder="amd64">
      </div>
      <div class="repo-field">
        <label>Key URL <span style="text-transform:none; font-weight:400;">(https, fetched through the egress guard)</span></label>
        <input type="text" name="key_url" value="<?= esc((string) ($edit['key_url'] ?? '')) ?>" placeholder="https://download.example.com/debian/key.asc">
      </div>
      <div class="repo-field">
        <label>Allowed architectures</label>
        <div style="font-size:.72rem; color:var(--text-muted); font-family:var(--font-mono); padding-top:8px;"><?= esc(implode(', ', $architectures)) ?></div>
      </div>
    </div>

    <div style="height:12px;"></div>

    <div class="repo-field">
      <label>Armored public key <span style="text-transform:none; font-weight:400;">(paste it, or leave empty and use the key URL)</span></label>
      <textarea name="key_armor" rows="6" placeholder="-----BEGIN PGP PUBLIC KEY BLOCK-----"><?= esc((string) ($edit['key_armor'] ?? '')) ?></textarea>
    </div>

    <div class="repo-field" style="display:flex; align-items:flex-start; gap:8px; margin-top:8px;">
      <input type="checkbox" name="skip_verify" value="1" id="skip_verify" style="width:auto; margin-top:3px;">
      <label for="skip_verify" style="text-transform:none; font-weight:500; margin:0; font-size:.74rem; color:var(--text-secondary);">
        Save without verifying (deliberately offline). Verification normally fetches the release metadata and checks the
        signing key against the declared fingerprint; tick this only when the vendor cannot be reached on purpose.
      </label>
    </div>

    <div style="margin-top:12px; display:flex; gap:8px; align-items:center;">
      <button type="submit" class="btn btn-primary btn-sm"><?= $edit !== null ? 'Update repository' : 'Add repository' ?></button>
      <?php if ($edit !== null): ?>
        <a href="<?= base_url('plugins/repositories') ?>" class="btn btn-ghost btn-sm">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if ($plan['repos'] !== []): ?>
  <div class="card card-glass animate-in animate-in-delay-2" style="padding:16px 18px; margin-bottom:16px;">
    <div style="font-weight:700; font-size:0.9rem; margin-bottom:2px;">Compiled plan</div>
    <div style="font-size:.74rem; color:var(--text-muted); margin-bottom:12px;">Exactly what the daemon will write for each enabled repository. Nothing here is editable by hand.</div>
    <?php foreach ($plan['repos'] as $generated): ?>
      <div style="border:1px solid var(--color-border); border-radius:8px; padding:10px 12px; margin-bottom:10px; background:var(--color-surface-3);">
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:6px;">
          <span style="font-weight:600; font-size:.86rem;"><?= esc($generated['name']) ?></span>
          <span class="repo-chip"><?= esc($generated['sources_path']) ?></span>
          <span class="repo-chip"><?= esc($generated['keyring']) ?></span>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
          <pre class="repo-stanza"><?= esc($generated['source']) ?></pre>
          <pre class="repo-stanza"><?= esc($generated['pin']) ?></pre>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?= $this->endSection() ?>
