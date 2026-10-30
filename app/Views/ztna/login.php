<?php
/**
 * Clientless Gateway sign-in page.
 *
 * Standalone (no admin layout): this is what remote users see on the gateway
 * hostname, branded from the gateway's Company Branding settings. Two steps
 * share the page: 'password' and, for accounts with an authenticator app,
 * 'totp'.
 *
 * @var array  $settings
 * @var string $step
 * @var string $pendingUser
 */
$s        = $settings;
$theme    = in_array($s['portal_theme'] ?? 'light', ['light', 'gray', 'dark'], true) ? $s['portal_theme'] : 'light';
$hex      = static fn ($v, $d) => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $v) ? (string) $v : $d;
$brand    = $hex($s['brand_color'] ?? '', '#6366f1');
$brandHov = $hex($s['brand_color_hover'] ?? '', '#4f46e5');
$accent   = $hex($s['brand_accent_color'] ?? '', '#10b981');
$company  = (string) ($s['company_name'] ?? 'Micron Security');
$title    = (string) ($s['portal_title'] ?? 'Clientless Gateway');
$logo     = trim((string) ($s['logo_url'] ?? ''));
$favicon  = trim((string) ($s['favicon_url'] ?? '')) ?: base_url('logos/favicon.ico');
$notice   = trim((string) ($s['notice_banner'] ?? ''));
$noticeT  = in_array($s['notice_banner_type'] ?? 'info', ['info', 'warning', 'danger', 'success'], true) ? $s['notice_banner_type'] : 'info';
$helpLink = trim((string) ($s['help_link'] ?? ''));
$helpSafe = $helpLink !== '' && preg_match('#^(https?://|mailto:|tel:)#i', $helpLink) === 1;
$initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $company) ?: 'G', 0, 2));
?>
<!DOCTYPE html>
<html lang="en" data-portal-theme="<?= esc($theme) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= esc($title) ?> — Sign in</title>
  <link rel="icon" href="<?= esc($favicon) ?>">
  <link rel="preload" href="<?= base_url('assets/fonts/inter-latin.woff2') ?>?v=1" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= base_url('assets/fonts/fonts.css') ?>?v=1">
  <style>
    :root {
      --brand: <?= esc($brand) ?>;
      --brand-hover: <?= esc($brandHov) ?>;
      --accent: <?= esc($accent) ?>;
      --page: #f4f6fb;
      --card: #ffffff;
      --text: #0f172a;
      --muted: #5b6475;
      --border: #dfe3ec;
      --field: #ffffff;
    }
    [data-portal-theme="gray"] { --page: #e9ecf1; --card: #f8f9fb; --border: #cfd5df; --field: #ffffff; }
    [data-portal-theme="dark"] { --page: #0b1020; --card: #121a2e; --text: #e7ecf5; --muted: #9aa5b8; --border: #26324b; --field: #0e1527; }

    * { box-sizing: border-box; }
    html, body { margin: 0; min-height: 100%; }
    body {
      font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
      background: var(--page);
      color: var(--text);
      -webkit-font-smoothing: antialiased;
    }
    .gw-shell { min-height: 100vh; display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); }

    /* Brand panel */
    .gw-hero {
      position: relative; overflow: hidden; color: #fff;
      background: linear-gradient(145deg, var(--brand) 0%, var(--brand-hover) 55%, #0b1020 140%);
      padding: 48px 56px; display: flex; flex-direction: column; justify-content: space-between; gap: 24px;
    }
    .gw-hero::before, .gw-hero::after {
      content: ""; position: absolute; border-radius: 50%; pointer-events: none;
      background: radial-gradient(circle, rgba(255,255,255,0.18), rgba(255,255,255,0) 70%);
    }
    .gw-hero::before { width: 520px; height: 520px; right: -160px; top: -140px; }
    .gw-hero::after { width: 380px; height: 380px; left: -120px; bottom: -120px; }
    .gw-hero > * { position: relative; z-index: 1; }
    .gw-brand { display: flex; align-items: center; gap: 12px; font-weight: 700; letter-spacing: 0.02em; }
    .gw-brand-mark {
      width: 40px; height: 40px; border-radius: 10px; background: rgba(255,255,255,0.16);
      display: grid; place-items: center; font-weight: 800; font-size: 0.95rem;
      border: 1px solid rgba(255,255,255,0.28);
    }
    .gw-hero h1 { font-size: clamp(1.8rem, 2.6vw, 2.6rem); line-height: 1.15; margin: 0 0 14px; letter-spacing: -0.02em; max-width: 560px; }
    .gw-hero p { font-size: 1rem; line-height: 1.6; margin: 0; opacity: 0.88; max-width: 520px; }
    .gw-features { list-style: none; padding: 0; margin: 32px 0 0; display: grid; gap: 14px; max-width: 520px; }
    .gw-features li { display: flex; gap: 12px; align-items: flex-start; font-size: 0.92rem; line-height: 1.45; }
    .gw-features svg { flex-shrink: 0; width: 22px; height: 22px; padding: 4px; border-radius: 6px; background: rgba(255,255,255,0.16); }
    .gw-hero-foot { font-size: 0.78rem; opacity: 0.75; }

    /* Sign-in panel */
    .gw-panel { display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 40px 24px; }
    .gw-card {
      width: 100%; max-width: 410px; background: var(--card); border: 1px solid var(--border);
      border-radius: 16px; padding: 36px 34px 30px; box-shadow: 0 18px 50px rgba(15, 23, 42, 0.10);
    }
    .gw-logo { display: flex; justify-content: center; margin-bottom: 18px; min-height: 44px; }
    .gw-logo img { max-height: 54px; max-width: 220px; object-fit: contain; }
    .gw-card h2 { font-size: 1.35rem; margin: 0 0 4px; text-align: center; letter-spacing: -0.01em; }
    .gw-sub { color: var(--muted); font-size: 0.86rem; text-align: center; margin: 0 0 24px; }
    .gw-field { margin-bottom: 16px; }
    .gw-field label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 6px; color: var(--muted); }
    .gw-input-wrap { position: relative; }
    .gw-input-wrap svg.lead { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; color: var(--muted); pointer-events: none; }
    .gw-input {
      width: 100%; height: 46px; border-radius: 10px; border: 1px solid var(--border); background: var(--field);
      color: var(--text); font: inherit; font-size: 0.95rem; padding: 0 44px 0 40px; outline: none;
      transition: border-color .15s ease, box-shadow .15s ease;
    }
    .gw-input:focus { border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 22%, transparent); }
    .gw-input.otp { padding: 0 14px; text-align: center; letter-spacing: 0.5em; font-size: 1.3rem; font-weight: 700; }
    .gw-eye { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); background: none; border: 0; padding: 8px; cursor: pointer; color: var(--muted); border-radius: 8px; }
    .gw-eye:focus-visible { outline: 2px solid var(--brand); }
    .gw-btn {
      width: 100%; height: 46px; border: 0; border-radius: 10px; background: var(--brand); color: #fff;
      font: inherit; font-weight: 700; font-size: 0.95rem; cursor: pointer; margin-top: 6px;
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      transition: background .15s ease, transform .15s ease, box-shadow .15s ease;
    }
    .gw-btn:hover { background: var(--brand-hover); box-shadow: 0 8px 20px color-mix(in srgb, var(--brand) 35%, transparent); }
    .gw-btn:active { transform: translateY(1px); }
    .gw-btn[disabled] { opacity: .7; cursor: progress; }
    .gw-alert { border-radius: 10px; padding: 10px 12px; font-size: 0.84rem; margin-bottom: 16px; line-height: 1.45; border: 1px solid; }
    .gw-alert.error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .gw-alert.info { background: #eff6ff; color: #1e3a8a; border-color: #bfdbfe; }
    .gw-alert.warning { background: #fffbeb; color: #92400e; border-color: #fde68a; }
    .gw-alert.danger { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .gw-alert.success { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
    [data-portal-theme="dark"] .gw-alert { background: rgba(255,255,255,0.04); color: var(--text); border-color: var(--border); }
    .gw-row { display: flex; justify-content: space-between; align-items: center; margin-top: 18px; font-size: 0.82rem; }
    .gw-row a { color: var(--brand); text-decoration: none; font-weight: 600; }
    .gw-row a:hover { text-decoration: underline; }
    .gw-secure { display: flex; align-items: center; justify-content: center; gap: 6px; color: var(--muted); font-size: 0.76rem; margin-top: 22px; }
    .gw-secure svg { width: 14px; height: 14px; color: var(--accent); }
    .gw-foot { margin-top: 22px; font-size: 0.75rem; color: var(--muted); text-align: center; max-width: 410px; }

    @media (max-width: 900px) {
      .gw-shell { grid-template-columns: 1fr; }
      .gw-hero { padding: 24px 20px; gap: 14px; }
      .gw-hero h1 { font-size: 1.5rem; }
      .gw-features, .gw-hero-foot { display: none; }
      .gw-panel { padding: 24px 16px 32px; }
      .gw-card { padding: 28px 22px 24px; }
    }
    @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    <?= str_ireplace('</style', '', (string) ($s['custom_css'] ?? '')) ?>
  </style>
</head>
<body>
<div class="gw-shell">
  <section class="gw-hero" aria-label="<?= esc($company) ?>">
    <div class="gw-brand">
      <span class="gw-brand-mark" aria-hidden="true"><?= esc($initials) ?></span>
      <span><?= esc($company) ?></span>
    </div>

    <div>
      <h1><?= esc($s['welcome_heading'] ?? 'Zero-Trust Clientless Access Portal') ?></h1>
      <p><?= esc($s['welcome_subtitle'] ?? '') ?></p>
      <ul class="gw-features">
        <li>
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
          <span>Desktops, terminals and web applications in your browser. Nothing to install.</span>
        </li>
        <li>
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
          <span>Encrypted end to end. Internal systems are never exposed to the internet.</span>
        </li>
        <li>
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
          <span>You only see the applications your role allows.</span>
        </li>
      </ul>
    </div>

    <div class="gw-hero-foot"><?= esc($title) ?></div>
  </section>

  <main class="gw-panel">
    <div class="gw-card">
      <?php if ($logo !== ''): ?>
        <div class="gw-logo"><img src="<?= esc($logo) ?>" alt="<?= esc($company) ?>"></div>
      <?php endif; ?>

      <?php if ($step === 'totp'): ?>
        <h2>Two-step verification</h2>
        <p class="gw-sub">Enter the 6-digit code from your authenticator app<?= $pendingUser !== '' ? ' for <strong>' . esc($pendingUser) . '</strong>' : '' ?>.</p>
      <?php else: ?>
        <h2>Sign in</h2>
        <p class="gw-sub">Use your <?= esc($company) ?> account to continue.</p>
      <?php endif; ?>

      <?php if ($notice !== '' && $step !== 'totp'): ?>
        <div class="gw-alert <?= esc($noticeT) ?>" role="note"><?= esc($notice) ?></div>
      <?php endif; ?>
      <?php if (session()->has('error')): ?>
        <div class="gw-alert error" role="alert"><?= esc(session('error')) ?></div>
      <?php elseif (session()->has('info')): ?>
        <div class="gw-alert info" role="status"><?= esc(session('info')) ?></div>
      <?php endif; ?>

      <?php if ($step === 'totp'): ?>
        <form method="post" action="<?= base_url('vpn/clientless-gateway/login/verify') ?>" onsubmit="this.querySelector('button').disabled=true">
          <?= csrf_field() ?>
          <div class="gw-field">
            <label for="totp_code">Verification code</label>
            <input class="gw-input otp" type="text" id="totp_code" name="totp_code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus>
          </div>
          <button class="gw-btn" type="submit">Verify and continue</button>
        </form>
        <div class="gw-row">
          <a href="<?= base_url('vpn/clientless-gateway/logout') ?>">Use a different account</a>
          <?php if ($helpSafe): ?><a href="<?= esc($helpLink) ?>" target="_blank" rel="noopener"><?= esc($s['help_link_text'] ?? 'Contact Helpdesk') ?></a><?php endif; ?>
        </div>
      <?php else: ?>
        <form method="post" action="<?= base_url('vpn/clientless-gateway/login') ?>" onsubmit="this.querySelector('button[type=submit]').disabled=true">
          <?= csrf_field() ?>
          <div class="gw-field">
            <label for="username">Username</label>
            <div class="gw-input-wrap">
              <svg class="lead" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
              <input class="gw-input" type="text" id="username" name="username" value="<?= esc(old('username') ?? '') ?>" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="64" required autofocus>
            </div>
          </div>
          <div class="gw-field">
            <label for="password">Password</label>
            <div class="gw-input-wrap">
              <svg class="lead" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
              <input class="gw-input" type="password" id="password" name="password" autocomplete="current-password" required>
              <button class="gw-eye" type="button" aria-label="Show password" aria-pressed="false" onclick="gwTogglePw(this)">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              </button>
            </div>
          </div>
          <button class="gw-btn" type="submit">
            Sign in
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
          </button>
        </form>
        <?php if ($helpSafe): ?>
          <div class="gw-row" style="justify-content:center;">
            <a href="<?= esc($helpLink) ?>" target="_blank" rel="noopener"><?= esc($s['help_link_text'] ?? 'Contact Helpdesk') ?></a>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="gw-secure">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        Secure connection<?= (int) ($s['require_mfa'] ?? 0) === 1 ? ' · Two-step verification required' : '' ?>
      </div>
    </div>
    <div class="gw-foot"><?= esc($s['footer_text'] ?? '') ?></div>
  </main>
</div>
<script>
function gwTogglePw(btn) {
  var input = document.getElementById('password');
  var show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  btn.setAttribute('aria-pressed', show ? 'true' : 'false');
  btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
}
</script>
</body>
</html>
