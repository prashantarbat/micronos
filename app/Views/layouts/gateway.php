<?php
/**
 * Layout for Clientless Gateway users (portal pages on the gateway hostname).
 *
 * Same stylesheet as the admin layout, so the portal's cards and badges render
 * identically, but no admin sidebar or menu: a portal user has no access to
 * those pages. The top bar carries the company name and a sign-out link.
 */
$_appearance = config('Appearance');
$_gwTheme    = $_appearance->resolveTheme(((new \App\Models\SystemSettingsModel())->getSettings())['theme'] ?? null);
$_gwSettings = (new \App\Models\ZtnaSettingsModel())->getSettings();
$_gwPortal   = in_array($_gwSettings['portal_theme'] ?? 'light', ['light', 'gray'], true) ? 'light' : 'dark';
$_gwFavicon  = trim((string) ($_gwSettings['favicon_url'] ?? '')) ?: base_url('logos/favicon.ico');
$_gwUser     = \App\Libraries\GatewayIdentity::current();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= esc($_gwTheme) ?>" data-mode="<?= esc($_gwPortal) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= esc($title ?? 'Clientless Gateway') ?></title>
  <link rel="preload" href="<?= base_url('assets/fonts/inter-latin.woff2') ?>?v=1" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= base_url('css/micronos.css') ?>?v=<?= @filemtime(FCPATH . 'css/micronos.css') ?: '1' ?>">
  <link rel="icon" href="<?= esc($_gwFavicon) ?>">
  <style>
    .gw-topbar {
      position: sticky; top: 0; z-index: 50; display: flex; align-items: center; justify-content: space-between;
      gap: 12px; padding: 10px 24px; background: var(--color-surface-1, #fff);
      border-bottom: 1px solid var(--color-border-bright, #e2e8f0);
    }
    .gw-topbar-brand { font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 10px; min-width: 0; }
    .gw-topbar-brand img { max-height: 30px; max-width: 160px; object-fit: contain; }
    .gw-topbar-user { display: flex; align-items: center; gap: 12px; font-size: 0.82rem; color: var(--text-muted); }
    .gw-signout {
      display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px;
      border: 1px solid var(--color-border-bright, #e2e8f0); color: var(--text-primary); text-decoration: none; font-weight: 600;
    }
    .gw-signout:hover { border-color: var(--brand-primary, #6366f1); color: var(--brand-primary, #6366f1); }
    #pageMain { padding: 24px; max-width: 1400px; margin: 0 auto; }
    @media (max-width: 640px) { .gw-topbar { padding: 10px 16px; } #pageMain { padding: 16px; } .gw-topbar-user .gw-who { display: none; } }
  </style>
</head>
<body>
  <header class="gw-topbar">
    <div class="gw-topbar-brand">
      <?php if (trim((string) ($_gwSettings['logo_url'] ?? '')) !== ''): ?>
        <img src="<?= esc($_gwSettings['logo_url']) ?>" alt="">
      <?php endif; ?>
      <span><?= esc($_gwSettings['company_name'] ?? 'Clientless Gateway') ?></span>
    </div>
    <?php if ($_gwUser !== null): ?>
      <div class="gw-topbar-user">
        <span class="gw-who">Signed in as <strong><?= esc($_gwUser['username']) ?></strong></span>
        <a class="gw-signout" href="<?= base_url('vpn/clientless-gateway/logout') ?>">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
          Sign out
        </a>
      </div>
    <?php endif; ?>
  </header>
  <main id="pageMain" class="main-content">
    <?= $this->renderSection('content') ?>
  </main>
</body>
</html>
