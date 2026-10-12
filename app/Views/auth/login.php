<?php
// Resolved by Auth::appearanceContext() — falls back to the dark defaults so the
// page still renders if it is ever reached without those view vars.
$_theme = $appTheme ?? 'dark';
$_mode = $appMode ?? 'dark';
$_isLight = $appIsLight ?? false;
$_logo = $_isLight ? 'logos/logo_bk.png' : 'logos/logo_wh.png';
$_bannerOn = !empty($settings['webgui_login_banner_enabled']);
$_bannerText = trim((string) ($settings['webgui_login_banner'] ?? ''));
$_bannerTitle = trim((string) ($settings['webgui_login_banner_title'] ?? '')) ?: 'Notice — Authorised Access Only';
$_showDisclaimer = $_bannerOn && $_bannerText !== '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= esc($_theme) ?>" data-mode="<?= esc($_mode) ?>">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Micron OS — Enterprise Firewall — Secure Sign In">
  <title><?= esc($title ?? 'Sign In — ' . \App\Libraries\AppVersion::getProjectName()) ?></title>
  <!-- Primary font subset, preloaded — see app/Views/layouts/main.php. Standalone
       page: it does not use the layout, so it needs its own copy. -->
  <link rel="preload" href="<?= base_url('assets/fonts/inter-latin.woff2') ?>?v=1" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet"
    href="<?= base_url('css/micronos.css') ?>?v=<?= @filemtime(FCPATH . 'css/micronos.css') ?: '1' ?>">
  <link rel="icon" href="<?= base_url('logos/favicon.ico') ?>" type="image/x-icon">
  <link rel="shortcut icon" href="<?= base_url('logos/favicon.ico') ?>" type="image/x-icon">
</head>

<body>
  <div class="login-page">

    <!-- Animated background -->
    <div class="login-bg">
      <div class="login-bg-grid"></div>
      <div class="login-bg-orb one"></div>
      <div class="login-bg-orb two"></div>
      <div class="login-bg-orb three"></div>
    </div>

    <!-- Login card -->
    <div class="login-card animate-in">
      <div class="login-card-inner">

        <!-- Logo: logo_bk.png on light mode, logo_wh.png on dark mode -->
        <div class="login-logo">
          <img src="<?= base_url('logos/logo_bk.png') ?>" alt="Micron Infosec &amp; IT Solutions LLP"
            class="login-logo-img logo-light" <?= !$_isLight ? 'style="display:none;"' : '' ?> />
          <img src="<?= base_url('logos/logo_wh.png') ?>" alt="Micron Infosec &amp; IT Solutions LLP"
            class="login-logo-img logo-dark" <?= $_isLight ? 'style="display:none;"' : '' ?> />
        </div>

        <!-- Flash messages -->
        <?php if (session()->has('error')): ?>
          <div class="alert alert-error" style="margin-bottom:20px;">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
              style="width:18px;height:18px;flex-shrink:0;">
              <path stroke-linecap="round" stroke-linejoin="round"
                d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span><?= esc(session('error')) ?></span>
          </div>
        <?php endif; ?>
        <?php if (session()->has('info')): ?>
          <div class="alert alert-info" style="margin-bottom:20px;">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
              style="width:18px;height:18px;flex-shrink:0;">
              <path stroke-linecap="round" stroke-linejoin="round"
                d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span><?= esc(session('info')) ?></span>
          </div>
        <?php endif; ?>

        <h1 class="login-heading">Welcome back</h1>
        <p class="login-subheading">Sign in to your <?= esc(\App\Libraries\AppVersion::getProjectName()) ?> management console</p>

        <!-- Login Form (gated by the disclaimer modal when one is configured) -->
        <form action="<?= base_url('auth/login') ?>" method="POST" id="loginForm" novalidate <?= $_showDisclaimer ? 'aria-hidden="true"' : '' ?>>
          <?= csrf_field() ?>
          <input type="hidden" name="disclaimer_accepted" id="disclaimerAccepted"
            value="<?= $_showDisclaimer ? '0' : '1' ?>">

          <div class="form-group">
            <label class="form-label" for="username">Username</label>
            <div class="input-icon-wrap">
              <svg class="icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
              <input type="text" id="username" name="username" class="form-control" placeholder="Username"
                value="<?= esc(old('username')) ?>" autocomplete="username" autofocus required>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="password">
              Password
              <a href="#" style="float:right; font-size:0.78rem; font-weight:500;">Forgot password?</a>
            </label>
            <div class="input-icon-wrap">
              <svg class="icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
              </svg>
              <input type="password" autocomplete="current-password" id="password" name="password" class="form-control"
                placeholder="••••••••" autocomplete="current-password" required>
            </div>
          </div>

          <div class="login-submit">
            <button type="submit" class="btn btn-primary btn-lg btn-full" id="loginBtn">
              <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
              </svg>
              Sign In to Console
            </button>
          </div>
        </form>

        <?php
          $ssoActive = [];
          try {
              $ssoActive = (new \App\Models\SsoProviderModel())->getEnabledProviders();
          } catch (\Throwable $e) {}
        ?>

        <?php if (! empty($ssoActive)): ?>
          <div style="margin-top:20px; text-align:center;">
            <div style="display:flex; align-items:center; gap:12px; margin-bottom:14px;">
              <div style="flex:1; height:1px; background:rgba(255,255,255,0.1);"></div>
              <span style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.06em; font-weight:600;">Or Federated SSO</span>
              <div style="flex:1; height:1px; background:rgba(255,255,255,0.1);"></div>
            </div>
            <div style="display:flex; flex-direction:column; gap:8px;">
              <?php foreach ($ssoActive as $sp): ?>
                <a href="<?= base_url('auth/sso/login/' . $sp['slug']) ?>" class="btn btn-ghost btn-full" style="display:flex; align-items:center; justify-content:center; gap:8px; border:1px solid rgba(255,255,255,0.12); padding:10px 14px; text-decoration:none;">
                  <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-indigo, #818cf8);">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                  </svg>
                  <span>Sign in with <?= esc($sp['name']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Footer info -->
        <div class="login-footer">
          <span><?= esc(\App\Libraries\AppVersion::getFullVersionString()) ?></span>
          <span style="margin:0 8px;">·</span>
          <span>Linux Network Firewall Appliance</span>
        </div>

      </div><!-- /login-card-inner -->

      <!-- Decorative bottom glow line -->
      <div
        style="height:1px; background: linear-gradient(90deg, transparent, var(--color-cyan), var(--color-purple), transparent); margin-top:-1px; border-radius: 0 0 var(--radius-xl) var(--radius-xl);">
      </div>

    </div><!-- /login-card -->

  </div><!-- /login-page -->

  <?php if ($_showDisclaimer): ?>
    <!-- Disclaimer / Login Banner — must be accepted before signing in -->
    <div class="login-disclaimer-overlay" id="disclaimerOverlay" role="dialog" aria-modal="true"
      aria-labelledby="disclaimerTitle">
      <div class="login-disclaimer-card animate-in">
        <div class="login-disclaimer-head">
          <span class="login-disclaimer-badge">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round"
                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
          </span>
          <h2 id="disclaimerTitle" class="login-disclaimer-title"><?= esc($_bannerTitle) ?></h2>
        </div>
        <div class="login-disclaimer-body"><?= nl2br(esc($_bannerText)) ?></div>
        <label class="login-disclaimer-check">
          <input type="checkbox" id="disclaimerAck">
          <span>I have read and accept the notice above.</span>
        </label>
        <button type="button" class="btn btn-primary btn-lg btn-full" id="disclaimerAccept" disabled>
          Accept &amp; Continue
        </button>
      </div>
    </div>
  <?php endif; ?>

  <script>
    // Loading state on submit
    document.getElementById('loginForm').addEventListener('submit', function (e) {
      if (document.getElementById('disclaimerAccepted').value !== '1') {
        e.preventDefault();
        return;
      }
      const btn = document.getElementById('loginBtn');
      btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg> Authenticating…';
      btn.disabled = true;
    });

    <?php if ($_showDisclaimer): ?>
        (function () {
          // Same notice already accepted in this tab (e.g. after a failed login) → skip.
          var ACK_KEY = 'micronos_disclaimer_ack';
          var bannerSignature = <?= json_encode(substr(hash('sha256', $_bannerTitle . '\n' . $_bannerText), 0, 16)) ?>;

          function unlockForm() {
            document.getElementById('disclaimerAccepted').value = '1';
            var form = document.getElementById('loginForm');
            form.removeAttribute('aria-hidden');
            form.classList.remove('is-locked');
          }

          function dismiss() {
            document.getElementById('disclaimerOverlay').remove();
            unlockForm();
            var u = document.getElementById('username');
            if (u) u.focus();
          }

          if (sessionStorage.getItem(ACK_KEY) === bannerSignature) {
            dismiss();
            return;
          }

          var form = document.getElementById('loginForm');
          form.classList.add('is-locked');

          var ack = document.getElementById('disclaimerAck');
          var accept = document.getElementById('disclaimerAccept');

          ack.addEventListener('change', function () { accept.disabled = !ack.checked; });

          accept.addEventListener('click', function () {
            try { sessionStorage.setItem(ACK_KEY, bannerSignature); } catch (err) { /* private mode */ }
            dismiss();
          });
        })();
    <?php endif; ?>
  </script>
  <style>
    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }
  </style>
</body>

</html>