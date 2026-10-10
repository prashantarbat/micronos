<?php
/**
 * Theme & Layout Switcher Popover
 * Included in both sidebar-mode topbar and topnav-mode right side.
 * Relies on JS function: toggleThemeSwitcher(), applyTheme(), applyLayout()
 */
// Inline helpers (safe to call multiple times — guarded by function_exists)
// NOTE: must be declared BEFORE first use — conditional declarations only take
// effect at runtime when execution reaches this block.
if (! function_exists('document_theme')) {
    function document_theme(): string {
        $h = \Config\Services::request()->getServer('HTTP_X_UI_THEME');
        return $h ?? 'dark'; // actual value is read from html data-theme in JS
    }
}
if (! function_exists('document_layout')) {
    function document_layout(): string {
        return 'sidebar'; // actual value is read from html data-layout in JS
    }
}

$_currentTheme  = (string) document_theme();
$_currentLayout = (string) document_layout();

// Theme catalogue — single source of truth in Config\Appearance.
// Light-base themes (mode "light") are listed after the dark ones.
$themes = [];
foreach (config('Appearance')->themes as $id => $meta) {
    $themes[] = ['id' => $id, 'label' => $meta['label'], 'dot' => $meta['dot'], 'mode' => $meta['mode']];
}
// Visually separate the light group from the dark group with a heading.
$hasLightGroup = false;
?>
<!-- Theme & Layout Switcher Popover -->
<div class="theme-switcher-popover" id="themeSwitcherPopover">

  <!-- Header -->
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <span style="font-size:0.78rem; font-weight:700; color:var(--text-primary);">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle;margin-right:5px;"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
      Appearance
    </span>
    <button type="button" onclick="toggleThemeSwitcher()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1rem;line-height:1;padding:2px 4px;">✕</button>
  </div>

  <!-- THEMES -->
  <p style="font-size:0.65rem; font-weight:700; letter-spacing:0.1em; text-transform:uppercase; color:var(--text-muted); margin-bottom:8px;">Colour Theme</p>
  <div class="theme-grid">
    <?php foreach ($themes as $t): ?>
    <?php if ($t['mode'] === 'light' && ! $hasLightGroup): $hasLightGroup = true; ?>
      <span class="theme-group-label">Light</span>
    <?php endif; ?>
    <button type="button"
            class="theme-swatch"
            data-theme-id="<?= esc($t['id'], 'attr') ?>"
            onclick="applyTheme('<?= $t['id'] ?>')"
            title="<?= esc($t['label']) ?>">
      <span class="theme-swatch-dot" style="background: <?= $t['dot'] ?>;"></span>
      <span class="theme-swatch-label"><?= esc($t['label']) ?></span>
    </button>
    <?php endforeach; ?>
  </div>

  <div style="height:1px; background:var(--color-border); margin:12px 0;"></div>

  <!-- LAYOUTS -->
  <p style="font-size:0.65rem; font-weight:700; letter-spacing:0.1em; text-transform:uppercase; color:var(--text-muted); margin-bottom:8px;">Layout Preset</p>
  <div class="layout-grid">

    <!-- Sidebar -->
    <button type="button" class="layout-card" data-layout-id="sidebar" onclick="applyLayout('sidebar')">
      <div class="layout-preview lp-sidebar">
        <div class="lp-side"></div>
        <div class="lp-main">
          <div class="lp-topbar"></div>
          <div class="lp-content">
            <div class="lp-row"></div>
            <div class="lp-row"></div>
            <div class="lp-row"></div>
          </div>
        </div>
      </div>
      <span class="layout-card-label">Sidebar</span>
    </button>

    <!-- Top Nav -->
    <button type="button" class="layout-card" data-layout-id="topnav" onclick="applyLayout('topnav')">
      <div class="layout-preview lp-topnav">
        <div class="lp-topbar">
          <div class="lp-dot"></div>
          <div class="lp-dot"></div>
          <div class="lp-dot"></div>
          <div class="lp-dot"></div>
        </div>
        <div class="lp-content">
          <div class="lp-row"></div>
          <div class="lp-row"></div>
          <div class="lp-row"></div>
        </div>
      </div>
      <span class="layout-card-label">Top Nav</span>
    </button>

  </div>

  <div style="height:1px; background:var(--color-border); margin:12px 0;"></div>

  <!-- Save to DB button -->
  <button type="button" id="saveAppearanceBtn" onclick="saveAppearanceToDB()"
          class="btn btn-primary btn-sm btn-full" style="font-size:0.75rem;">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
    Save as default
  </button>

</div><!-- /theme-switcher-popover -->
