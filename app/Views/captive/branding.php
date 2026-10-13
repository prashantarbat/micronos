<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
  /** @var array $zone */
  /** @var array $custom  captive_customizations row (or defaults) */
  /** @var array $walled  captive_walled_garden rows */
  $c = $custom ?? [];
  $title   = $c['venue_title']     ?? 'Guest Network Access';
  $welcome = $c['welcome_message'] ?? '';
  $terms   = $c['terms_text']      ?? '';
  $primary = $c['primary_color']   ?? '#4f46e5';
  $bg      = $c['bg_color']        ?? '#0f172a';
  $logo    = $c['logo_path']       ?? '';
  $css     = $c['custom_css']      ?? '';
  $flashOk = session()->getFlashdata('success') ?? session()->getFlashdata('message');
?>

<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <a href="<?= base_url('captive') ?>">Captive Portal</a>
      <span class="sep">/</span>
      <span class="current">Branding — <?= esc($zone['name']) ?></span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-purple">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>
        </svg>
      </div>
      Guest Splash Customization
    </h1>
    <p class="page-subtitle">Branding and walled garden for zone <strong><?= esc($zone['name']) ?></strong>. Branding is live immediately; walled-garden changes apply on the next <em>Apply Rules</em>.</p>
  </div>
  <div class="page-header-actions">
    <a href="<?= base_url('portal/auth/' . rawurlencode($zone['slug'])) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" id="previewSplashBtn">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
      Open Live Splash
    </a>
  </div>
</div>

<?php if ($flashOk): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:16px;"><?= esc($flashOk) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:16px;"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:18px;">
  <!-- Left: branding form -->
  <form method="POST" action="<?= base_url('captive/branding/' . rawurlencode($zone['id']) . '/save') ?>" id="brandingForm" style="display:flex; flex-direction:column; gap:16px;">
    <?= csrf_field() ?>
    <input type="hidden" name="zone_id" value="<?= esc($zone['id']) ?>">

    <div class="card card-glass animate-in">
      <div class="card-header"><span class="card-title">Identity &amp; Copy</span></div>
      <div style="padding:16px; display:flex; flex-direction:column; gap:14px;">
        <div>
          <label class="form-label" for="venue_title">Venue / Portal Title</label>
          <input type="text" id="venue_title" name="venue_title" class="form-control" maxlength="80" value="<?= esc($title) ?>" data-preview="pvTitle">
        </div>
        <div>
          <label class="form-label" for="welcome_message">Welcome Message</label>
          <textarea id="welcome_message" name="welcome_message" rows="2" class="form-control" maxlength="300" data-preview="pvWelcome"><?= esc($welcome) ?></textarea>
        </div>
        <div>
          <label class="form-label" for="logo_path">Logo Path <small style="color:var(--text-muted);">(relative to public/, e.g. logos/logo_wh.png)</small></label>
          <input type="text" id="logo_path" name="logo_path" class="form-control" value="<?= esc($logo) ?>" placeholder="logos/logo_wh.png">
        </div>
        <div>
          <label class="form-label" for="terms_text">Terms of Service <small style="color:var(--text-muted);">(plain text, shown for click-through zones)</small></label>
          <textarea id="terms_text" name="terms_text" rows="4" class="form-control" style="font-size:0.78rem;" data-preview="pvTerms"><?= esc($terms) ?></textarea>
        </div>
      </div>
    </div>

    <div class="card card-glass animate-in">
      <div class="card-header"><span class="card-title">Colors &amp; Style</span></div>
      <div style="padding:16px; display:grid; grid-template-columns:1fr 1fr; gap:12px;">
        <div>
          <label class="form-label" for="primary_color">Accent (buttons, focus)</label>
          <input type="color" id="primary_color" name="primary_color" class="form-control" value="<?= esc($primary) ?>" style="height:40px; padding:4px;">
        </div>
        <div>
          <label class="form-label" for="bg_color">Background</label>
          <input type="color" id="bg_color" name="bg_color" class="form-control" value="<?= esc($bg) ?>" style="height:40px; padding:4px;">
        </div>
        <div style="grid-column:1 / -1;">
          <label class="form-label" for="custom_css">Custom CSS <small style="color:var(--text-muted);">(advanced)</small></label>
          <textarea id="custom_css" name="custom_css" rows="4" class="form-control" style="font-family:var(--font-mono); font-size:0.75rem;" placeholder=".portal-card { border-radius: 24px; }"><?= esc($css) ?></textarea>
        </div>
      </div>
    </div>

    <div style="display:flex; justify-content:flex-end; gap:10px;">
      <a href="<?= base_url('captive') ?>" class="btn btn-secondary btn-sm">Back</a>
      <button type="submit" class="btn btn-primary btn-sm" id="saveBrandingBtn">Save Branding</button>
    </div>
  </form>

  <!-- Right: preview + walled garden -->
  <div style="display:flex; flex-direction:column; gap:16px;">
    <div class="card card-glass animate-in">
      <div class="card-header"><span class="card-title">Live Preview</span></div>
      <div style="padding:16px;">
        <div id="pvFrame" style="--pv-accent:<?= esc($primary) ?>; background:<?= esc($bg) ?>; border-radius:12px; padding:28px 20px; min-height:300px; display:flex; align-items:center; justify-content:center; border:1px solid var(--color-border); transition:background .25s ease;">
          <div style="width:100%; max-width:300px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:22px 18px; text-align:center; color:#f8fafc; backdrop-filter:blur(8px);">
            <?php if ($logo !== ''): ?>
              <img src="<?= esc(base_url($logo)) ?>" alt="" style="max-height:40px; margin-bottom:12px;">
            <?php endif; ?>
            <div id="pvTitle" style="font-size:1.15rem; font-weight:800; margin-bottom:6px;"><?= esc($title) ?></div>
            <div id="pvWelcome" style="font-size:0.74rem; opacity:.6; margin-bottom:16px;"><?= esc($welcome) ?></div>
            <?php if ($zone['auth_mode'] === 'voucher'): ?>
              <div style="border:2px solid var(--pv-accent); border-radius:8px; padding:9px; font-family:var(--font-mono); letter-spacing:3px; opacity:.5;">XXXX-XXXX</div>
            <?php else: ?>
              <div id="pvTerms" style="font-size:0.65rem; opacity:.45; max-height:56px; overflow:hidden; text-align:left; border:1px solid rgba(255,255,255,0.08); border-radius:6px; padding:8px;"><?= esc($terms) ?></div>
            <?php endif; ?>
            <div id="pvBtn" style="margin-top:12px; background:var(--pv-accent); border-radius:8px; padding:10px; font-weight:800; font-size:0.82rem; color:#fff;">Connect to Internet</div>
          </div>
        </div>
      </div>
    </div>

    <div class="card card-glass animate-in">
      <div class="card-header">
        <span class="card-title">Walled Garden</span>
        <span class="card-subtitle" style="display:block; font-size:0.72rem; color:var(--text-muted);">Hosts/IPs reachable before login. Apple, Google &amp; Microsoft CNA ranges are always included.</span>
      </div>
      <div style="padding:16px;">
        <form method="POST" action="<?= base_url('captive/walled-garden/save') ?>" style="display:grid; grid-template-columns:1fr 1fr auto; gap:8px; margin-bottom:12px;" id="walledForm">
          <?= csrf_field() ?>
          <input type="hidden" name="zone_id" value="<?= esc($zone['id']) ?>">
          <input type="text" name="target" class="form-control" placeholder="203.0.113.0/24 or pay.example.com" required style="font-family:var(--font-mono); font-size:0.78rem;">
          <input type="text" name="description" class="form-control" placeholder="Description" style="font-size:0.78rem;">
          <button type="submit" class="btn btn-primary btn-sm" id="addWalledBtn">Add</button>
        </form>

        <?php if (empty($walled)): ?>
          <p style="font-size:0.78rem; color:var(--text-muted); text-align:center; margin:12px 0;">No custom entries.</p>
        <?php else: ?>
          <table class="table" style="width:100%; font-size:0.78rem;">
            <tbody>
              <?php foreach ($walled as $w): ?>
                <tr>
                  <td style="font-family:var(--font-mono);"><?= esc($w['target']) ?></td>
                  <td style="color:var(--text-muted);"><?= esc($w['description'] ?? '') ?></td>
                  <td style="text-align:right;">
                    <form method="POST" action="<?= base_url('captive/walled-garden/' . (int) $w['id'] . '/delete') ?>" style="margin:0; display:inline;" onsubmit="return confirm('Remove <?= esc($w['target'], 'js') ?>?');">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-danger btn-sm" style="padding:2px 7px; font-size:0.7rem;">Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  document.querySelectorAll('[data-preview]').forEach((el) => {
    const target = document.getElementById(el.dataset.preview);
    if (target) el.addEventListener('input', () => { target.textContent = el.value; });
  });
  const frame = document.getElementById('pvFrame');
  document.getElementById('bg_color')?.addEventListener('input', (e) => { frame.style.background = e.target.value; });
  document.getElementById('primary_color')?.addEventListener('input', (e) => { frame.style.setProperty('--pv-accent', e.target.value); });
})();
</script>

<?= $this->endSection() ?>
