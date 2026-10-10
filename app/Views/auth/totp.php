<?php
// Same Interface Theme as the sign-in page (resolved by Auth::appearanceContext()).
$_theme = $appTheme ?? 'dark';
$_mode  = $appMode ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= esc($_theme) ?>" data-mode="<?= esc($_mode) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= esc($title ?? '2FA Verification — MicronOS') ?></title>
  <!-- Primary font subset, preloaded — see app/Views/layouts/main.php. Standalone
       page: it does not use the layout, so it needs its own copy. -->
  <link rel="preload" href="<?= base_url('assets/fonts/inter-latin.woff2') ?>?v=1" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= base_url('css/micronos.css') ?>?v=<?= @filemtime(FCPATH . 'css/micronos.css') ?: '1' ?>">
</head>
<body>
<div class="login-page">
  <div class="login-bg">
    <div class="login-bg-grid"></div>
    <div class="login-bg-orb one"></div>
    <div class="login-bg-orb two"></div>
  </div>

  <div class="login-card animate-in">
    <div class="login-card-inner">

      <div class="login-logo">
        <img src="<?= base_url('logos/logo_bk.png') ?>" alt="Micron Infosec &amp; IT Solutions LLP" class="login-logo-img logo-light" <?= $_mode !== 'light' ? 'style="display:none;"' : '' ?> />
        <img src="<?= base_url('logos/logo_wh.png') ?>" alt="Micron Infosec &amp; IT Solutions LLP" class="login-logo-img logo-dark" <?= $_mode === 'light' ? 'style="display:none;"' : '' ?> />
      </div>

      <?php if (session()->has('error')): ?>
        <div class="alert alert-error" style="margin-bottom:20px;">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;flex-shrink:0;">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <span><?= esc(session('error')) ?></span>
        </div>
      <?php endif; ?>

      <!-- TOTP icon -->
      <div style="text-align:center; margin-bottom:24px;">
        <div style="width:64px; height:64px; background:var(--color-purple-dim); border:2px solid rgba(124,58,237,0.3); border-radius:50%; display:inline-flex; align-items:center; justify-content:center; margin-bottom:12px;">
          <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-purple)" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2v-1a7 7 0 00-7-5.955A7 7 0 003 18v1a2 2 0 002 2h3zm0-9a3 3 0 116 0 3 3 0 01-6 0z"/>
          </svg>
        </div>
        <h1 class="login-heading">Two-Factor Verification</h1>
        <p class="login-subheading">
          Signed in as <strong style="color:var(--color-cyan)"><?= esc($username ?? 'user') ?></strong>.<br>
          Enter the 6-digit code from your authenticator app.
        </p>
      </div>

      <form action="<?= base_url('auth/totp') ?>" method="POST" id="totpForm">
        <?= csrf_field() ?>

        <div class="form-group">
          <label class="form-label" for="totp_code">Authentication Code</label>
          <input
            type="text"
            id="totp_code"
            name="totp_code"
            class="form-control"
            style="text-align:center; font-family:var(--font-mono); font-size:1.4rem; letter-spacing:0.3em;"
            placeholder="000000"
            maxlength="6"
            pattern="[0-9]{6}"
            inputmode="numeric"
            autocomplete="one-time-code"
            autofocus
            required
          >
          <?php if (ENVIRONMENT === 'development'): ?>
            <div class="form-hint" style="color:var(--color-amber);">
              🛠 Dev mode: use code <strong>123456</strong> to bypass TOTP.
            </div>
          <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-full">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
          </svg>
          Verify Code
        </button>
      </form>

      <div class="login-footer" style="margin-top:20px;">
        <a href="<?= base_url('auth/logout') ?>" style="color:var(--text-muted);">
          ← Cancel and sign out
        </a>
      </div>

    </div>
  </div>
</div>

<script>
// Auto-submit when 6 digits entered
document.getElementById('totp_code').addEventListener('input', function() {
  if (this.value.replace(/\D/g, '').length === 6) {
    this.value = this.value.replace(/\D/g, '').slice(0, 6);
    document.getElementById('totpForm').submit();
  }
});
</script>
</body>
</html>
