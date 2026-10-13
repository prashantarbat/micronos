<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
  <title><?= esc($branding['title'] ?? 'Connected!') ?> — You\'re Online</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap');
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
      font-family: 'Inter', sans-serif;
      -webkit-font-smoothing: antialiased;
    }
    body {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100dvh;
      padding: 20px;
    }
    .success-wrap {
      width: 100%;
      max-width: 400px;
      text-align: center;
      animation: pop 0.5s cubic-bezier(0.34,1.56,0.64,1);
    }
    @keyframes pop {
      from { opacity: 0; transform: scale(0.85); }
      to   { opacity: 1; transform: scale(1); }
    }
    .checkmark-ring {
      width: 90px; height: 90px;
      background: rgba(16,185,129,0.12);
      border: 3px solid #10b981;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px;
      font-size: 2.5rem;
    }
    .success-card {
      background: var(--card);
      border-radius: 16px;
      padding: 28px 24px;
      border: 1px solid rgba(255,255,255,0.06);
      box-shadow: 0 20px 60px rgba(0,0,0,0.5);
      margin-bottom: 16px;
    }
    .success-title {
      font-size: 1.6rem;
      font-weight: 900;
      color: #10b981;
      margin: 0 0 6px;
    }
    .success-sub {
      font-size: 0.85rem;
      color: rgba(255,255,255,0.5);
      margin: 0 0 20px;
    }
    .session-info {
      display: flex;
      flex-direction: column;
      gap: 8px;
      background: rgba(255,255,255,0.04);
      border-radius: 10px;
      padding: 14px;
      text-align: left;
      margin-bottom: 20px;
    }
    .session-row {
      display: flex;
      justify-content: space-between;
      font-size: 0.78rem;
    }
    .session-row .label { color: rgba(255,255,255,0.45); }
    .session-row .value { font-weight: 700; color: var(--text); font-family: monospace; }
    .btn-redirect {
      display: inline-block;
      background: var(--accent);
      color: #000;
      font-weight: 800;
      padding: 12px 28px;
      border-radius: 8px;
      text-decoration: none;
      font-size: 0.9rem;
      width: 100%;
      transition: opacity 0.2s;
    }
    .btn-redirect:hover { opacity: 0.9; }
    .footer-note {
      margin-top: 16px;
      font-size: 0.65rem;
      color: rgba(255,255,255,0.2);
    }
    .countdown { color: var(--accent); font-weight: 700; }
  </style>
</head>
<body>

<div class="success-wrap">
  <?php if (!empty($branding['logo_url'])): ?>
    <img src="<?= esc(base_url($branding['logo_url'])) ?>" alt="Logo" style="max-height:48px; margin-bottom:16px;">
  <?php endif; ?>

  <div class="success-card">
    <div class="checkmark-ring">✓</div>
    <h1 class="success-title">You're Online!</h1>
    <p class="success-sub">Your device has been authorized and internet access is active.</p>

    <div class="session-info">
      <div class="session-row">
        <span class="label">IP Address</span>
        <span class="value"><?= esc($session['ip_address'] ?? '—') ?></span>
      </div>
      <div class="session-row">
        <span class="label">Session Duration</span>
        <span class="value">
          <?php
            $dm = (int)($session['duration_minutes'] ?? 60);
            echo $dm >= 60 ? round($dm / 60, 1) . 'h' : $dm . ' min';
          ?>
        </span>
      </div>
      <div class="session-row">
        <span class="label">Data Quota</span>
        <span class="value">
          <?= (int)($session['data_quota_mb'] ?? 0) > 0
              ? esc($session['data_quota_mb']) . ' MB'
              : '∞ Unlimited' ?>
        </span>
      </div>
      <div class="session-row">
        <span class="label">Session Expires</span>
        <span class="value"><?= esc(substr($session['expires_at'] ?? '—', 0, 16)) ?></span>
      </div>
    </div>

    <?php if (!empty($branding['success_redirect_url'])): ?>
      <a href="<?= esc($branding['success_redirect_url']) ?>" class="btn-redirect" id="continueBtn">
        Continue Browsing (<span class="countdown" id="countdown">8</span>s)
      </a>
    <?php else: ?>
      <a href="http://www.gstatic.com/generate_204" class="btn-redirect">Browse the Internet →</a>
    <?php endif; ?>
  </div>

  <div class="footer-note">
    <?= esc($branding['footer_text'] ?? 'Powered by MicronOS Hotspot Engine') ?>
  </div>
</div>

<?php if (!empty($branding['success_redirect_url'])): ?>
<script>
let secs = 8;
const el  = document.getElementById('countdown');
const btn = document.getElementById('continueBtn');
const timer = setInterval(() => {
  secs--;
  if (el) el.textContent = secs;
  if (secs <= 0) {
    clearInterval(timer);
    window.location.href = <?= json_encode($branding['success_redirect_url']) ?>;
  }
}, 1000);
</script>
<?php endif; ?>

</body>
</html>
