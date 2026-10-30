<?php
/**
 * Shared page footer — author attribution, product version and help links.
 *
 * Rendered by the main layout, the sign-in pages and the error pages so the
 * attribution is identical everywhere. Values come from Config\Branding.
 *
 * @var string|null $footerVariant 'app' (inside the console) or 'standalone'
 */
$_brand   = config('Branding');
$_version = class_exists(\App\Libraries\AppVersion::class) ? (string) \App\Libraries\AppVersion::get() : '';
$_variant = $footerVariant ?? 'app';
?>
<footer class="app-footer app-footer--<?= esc($_variant, 'attr') ?>" role="contentinfo">
  <div class="app-footer__inner">
    <span class="app-footer__product">
      <?= esc($_brand->productName) ?><?php if ($_version !== ''): ?> <span class="app-footer__version">v<?= esc($_version) ?></span><?php endif; ?>
    </span>
    <span class="app-footer__sep" aria-hidden="true">·</span>
    <span class="app-footer__author">
      Author: <strong><?= esc($_brand->authorName) ?></strong>
      <span class="app-footer__sep" aria-hidden="true">·</span>
      <a href="<?= esc($_brand->projectUrl, 'attr') ?>" target="_blank" rel="noopener noreferrer"><?= esc($_brand->projectUrl) ?></a>
    </span>
    <?php if ($_variant === 'app'): ?>
    <span class="app-footer__links">
      <a href="<?= base_url('docs') ?>">Documentation</a>
      <button type="button" class="app-footer__palette" data-open-palette aria-keyshortcuts="Control+K Meta+K">Search <kbd>Ctrl K</kbd></button>
    </span>
    <?php endif; ?>
  </div>
</footer>
