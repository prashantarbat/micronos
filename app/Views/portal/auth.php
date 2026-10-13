<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <title><?= esc($branding['title'] ?? 'Guest Wi-Fi') ?></title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@600&display=swap');
    *, *::before, *::after { box-sizing: border-box; }
    :root {
      --bg:     <?= esc($branding['bg_color']     ?? '#0f172a') ?>;
      --card:   <?= esc($branding['card_color']   ?? '#1e293b') ?>;
      --accent: <?= esc($branding['accent_color'] ?? '#f59e0b') ?>;
      --text:   <?= esc($branding['text_color']   ?? '#f8fafc') ?>;
    }
    html, body {
      height: 100%;
      margin: 0;
      background: var(--bg);
      color: var(--text);
      font-family: 'Inter', -apple-system, sans-serif;
      -webkit-font-smoothing: antialiased;
    }
    body {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      min-height: 100dvh;
      padding: 20px;
    }

    .portal-wrap {
      width: 100%;
      max-width: 420px;
      animation: fadeUp 0.4s ease;
    }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(20px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    .portal-logo {
      text-align: center;
      margin-bottom: 20px;
    }
    .portal-logo img { max-height: 52px; }

    .portal-card {
      background: var(--card);
      border-radius: 16px;
      padding: 28px 24px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.5);
      border: 1px solid rgba(255,255,255,0.06);
    }

    .portal-title {
      font-size: 1.5rem;
      font-weight: 800;
      color: var(--text);
      margin: 0 0 6px;
      text-align: center;
    }
    .portal-subtitle {
      font-size: 0.82rem;
      color: rgba(255,255,255,0.5);
      text-align: center;
      margin: 0 0 22px;
    }

    .form-group { margin-bottom: 14px; }
    .form-label {
      display: block;
      font-size: 0.72rem;
      font-weight: 600;
      color: rgba(255,255,255,0.6);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 6px;
    }
    .form-input {
      width: 100%;
      padding: 12px 14px;
      background: rgba(255,255,255,0.06);
      border: 2px solid rgba(255,255,255,0.1);
      border-radius: 8px;
      color: var(--text);
      font-size: 1rem;
      font-family: 'JetBrains Mono', monospace;
      letter-spacing: 2px;
      text-align: center;
      text-transform: uppercase;
      transition: border-color 0.2s;
      outline: none;
    }
    .form-input:focus { border-color: var(--accent); }
    .form-input::placeholder { color: rgba(255,255,255,0.25); letter-spacing: 1px; font-size: 0.85rem; }

    .btn-connect {
      width: 100%;
      padding: 13px;
      background: var(--accent);
      border: none;
      border-radius: 8px;
      font-weight: 800;
      font-size: 0.95rem;
      color: #000;
      cursor: pointer;
      margin-top: 8px;
      transition: opacity 0.2s, transform 0.1s;
    }
    .btn-connect:hover { opacity: 0.9; }
    .btn-connect:active { transform: scale(0.98); }
    .btn-connect:disabled { opacity: 0.5; cursor: not-allowed; }

    .error-msg {
      background: rgba(239,68,68,0.12);
      border: 1px solid rgba(239,68,68,0.3);
      border-radius: 6px;
      padding: 10px 14px;
      font-size: 0.78rem;
      color: #fca5a5;
      margin-bottom: 14px;
      display: none;
    }
    .error-msg.visible { display: block; }

    .clickthrough-terms {
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 8px;
      padding: 12px 14px;
      font-size: 0.72rem;
      color: rgba(255,255,255,0.45);
      max-height: 100px;
      overflow-y: auto;
      margin-bottom: 12px;
      line-height: 1.5;
    }

    .accept-row {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin-bottom: 14px;
    }
    .accept-row input[type="checkbox"] {
      width: 16px;
      height: 16px;
      accent-color: var(--accent);
      flex-shrink: 0;
      margin-top: 2px;
    }
    .accept-row label { font-size: 0.78rem; color: rgba(255,255,255,0.6); cursor: pointer; }

    .portal-divider {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 18px 0;
      color: rgba(255,255,255,0.2);
      font-size: 0.7rem;
    }
    .portal-divider::before, .portal-divider::after {
      content: '';
      flex: 1;
      border-top: 1px solid rgba(255,255,255,0.08);
    }

    .spinner {
      display: none;
      width: 18px; height: 18px;
      border: 2px solid rgba(0,0,0,0.3);
      border-top-color: #000;
      border-radius: 50%;
      animation: spin 0.6s linear infinite;
      margin: 0 auto;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    .portal-footer {
      text-align: center;
      margin-top: 20px;
      font-size: 0.68rem;
      color: rgba(255,255,255,0.25);
    }

    <?= isset($branding['custom_css']) ? $branding['custom_css'] : '' ?>
  </style>
</head>
<body>

<div class="portal-wrap">
  <!-- Logo -->
  <?php if (!empty($branding['logo_url'])): ?>
    <div class="portal-logo">
      <img src="<?= esc(base_url($branding['logo_url'])) ?>" alt="<?= esc($branding['title'] ?? 'Logo') ?>">
    </div>
  <?php endif; ?>

  <div class="portal-card">
    <h1 class="portal-title"><?= esc($branding['title'] ?? 'Welcome to Wi-Fi') ?></h1>
    <p class="portal-subtitle"><?= esc($branding['subtitle'] ?? 'Enter your voucher code to get online') ?></p>

    <!-- Error message placeholder -->
    <div class="error-msg" id="errorMsg"></div>

    <form id="portalForm" method="POST" action="<?= base_url('portal/authenticate') ?>" autocomplete="off">
      <input type="hidden" name="zone_slug" value="<?= esc($zone['slug']) ?>">
      <input type="hidden" name="client_ip" value="<?= esc($clientIp ?? '') ?>">
      <input type="hidden" name="client_mac" value="<?= esc($clientMac ?? '') ?>">

      <?php if ($zone['auth_mode'] === 'voucher'): ?>
        <!-- Voucher Input -->
        <div class="form-group">
          <label class="form-label" for="code">Voucher Code</label>
          <input
            type="text"
            id="code"
            name="code"
            class="form-input"
            placeholder="XXXX-XXXX"
            maxlength="16"
            autofocus
            oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g,'').replace(/(.{4})(?=.)/g,'$1-').substring(0,9)"
          >
        </div>

      <?php elseif ($zone['auth_mode'] === 'clickthrough'): ?>
        <!-- Terms Acceptance -->
        <div class="clickthrough-terms">
          <?= $branding['terms_html'] ?? 'By connecting to this network you agree to our Terms of Service and Acceptable Use Policy.' ?>
        </div>
        <div class="accept-row">
          <input type="checkbox" id="accept_terms" name="accept_terms" value="1" required>
          <label for="accept_terms">I have read and agree to the Terms &amp; Conditions above.</label>
        </div>
        <input type="hidden" name="code" value="CLICKTHROUGH">

      <?php else: ?>
        <!-- Generic passthrough (RADIUS handled by daemon) -->
        <div class="form-group">
          <label class="form-label" for="username">Username</label>
          <input type="text" id="username" name="username" class="form-input" placeholder="username" style="letter-spacing:normal; text-transform:none;" autofocus>
        </div>
        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <input type="password" id="password" name="password" class="form-input" placeholder="••••••••" style="letter-spacing:3px; text-transform:none;">
        </div>
      <?php endif; ?>

      <button type="submit" class="btn-connect" id="connectBtn">
        <span id="btnText">Connect to Internet</span>
        <div class="spinner" id="spinner"></div>
      </button>
    </form>
  </div>

  <div class="portal-footer">
    <?= esc($branding['footer_text'] ?? 'Powered by MicronOS') ?>
  </div>
</div>

<script>
const form = document.getElementById('portalForm');
const btn  = document.getElementById('connectBtn');
const txt  = document.getElementById('btnText');
const spin = document.getElementById('spinner');
const err  = document.getElementById('errorMsg');

<?php if (!empty($flash_error)): ?>
err.textContent = <?= json_encode($flash_error) ?>;
err.classList.add('visible');
<?php endif; ?>

form.addEventListener('submit', function (e) {
  btn.disabled = true;
  txt.style.display = 'none';
  spin.style.display = 'block';
  err.classList.remove('visible');
});
</script>

</body>
</html>
