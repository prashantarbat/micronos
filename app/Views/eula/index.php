<?php
/**
 * Licence agreement — shown after sign-in until an administrator accepts the
 * current version. Standalone like the login page: no navigation to leave by.
 *
 * @var string $text       EULA.txt
 * @var string $version
 * @var bool   $accepted
 * @var bool   $canAccept
 */
$_isLight = $appIsLight ?? false;
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= esc($appTheme ?? 'micronos') ?>" data-mode="<?= esc($appMode ?? 'dark') ?>">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= esc($title) ?></title>
  <link rel="preload" href="<?= base_url('assets/fonts/inter-latin.woff2') ?>?v=1" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet"
    href="<?= base_url('css/micronos.css') ?>?v=<?= @filemtime(FCPATH . 'css/micronos.css') ?: '1' ?>">
  <link rel="icon" href="<?= base_url('logos/favicon.ico') ?>" type="image/x-icon">
  <style>
    .eula-card { max-width: 760px; width: calc(100% - 32px); }
    .eula-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
    .eula-head h1 { font-size: 1.15rem; font-weight: 700; margin: 0; color: var(--text-primary); }
    .eula-version { font-size: 0.75rem; color: var(--text-secondary); white-space: nowrap; }
    .eula-intro { font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 12px; }
    .eula-text {
      height: min(52vh, 460px); overflow-y: auto; margin: 0 0 16px; padding: 16px 18px;
      border: 1px solid var(--color-border-bright, var(--color-border)); border-radius: 10px;
      background: var(--bg-elevated, rgba(0,0,0,0.18)); color: var(--text-primary);
      font-family: var(--font-mono, ui-monospace, monospace); font-size: 0.78rem; line-height: 1.55;
      white-space: pre-wrap; word-wrap: break-word;
    }
    .eula-agree { display: flex; gap: 10px; align-items: flex-start; font-size: 0.85rem; color: var(--text-primary); margin-bottom: 16px; }
    .eula-agree input { margin-top: 3px; width: 16px; height: 16px; flex-shrink: 0; }
    .eula-actions { display: flex; gap: 12px; justify-content: flex-end; flex-wrap: wrap; }
    .eula-actions form { margin: 0; }
    .eula-note { font-size: 0.82rem; color: var(--text-secondary); margin: 0 0 16px; }
  </style>
</head>

<body>
  <div class="login-page">
    <div class="login-bg">
      <div class="login-bg-grid"></div>
      <div class="login-bg-orb one"></div>
      <div class="login-bg-orb two"></div>
    </div>

    <div class="login-card eula-card animate-in">
      <div class="login-card-inner">
        <div class="login-logo">
          <img src="<?= base_url('logos/logo_bk.png') ?>" alt="Micron OS" class="login-logo-img logo-light" <?= ! $_isLight ? 'style="display:none;"' : '' ?> />
          <img src="<?= base_url('logos/logo_wh.png') ?>" alt="Micron OS" class="login-logo-img logo-dark" <?= $_isLight ? 'style="display:none;"' : '' ?> />
        </div>

        <?php if (session()->has('error')): ?>
          <div class="alert alert-error" style="margin-bottom:16px;"><span><?= esc(session('error')) ?></span></div>
        <?php endif; ?>

        <div class="eula-head">
          <h1>Micron OS Licence Agreement</h1>
          <span class="eula-version">Version <?= esc($version) ?></span>
        </div>
        <p class="eula-intro">Please read the agreement below. The appliance can be managed once an administrator accepts it.</p>

        <div class="eula-text" tabindex="0" aria-label="Licence agreement text"><?= esc($text) ?></div>

        <?php if ($canAccept): ?>
          <form method="post" action="<?= base_url('eula/accept') ?>" id="eulaAcceptForm">
            <?= csrf_field() ?>
            <label class="eula-agree">
              <input type="checkbox" name="agree" value="1" id="eulaAgree">
              <span>I have read and accept the Micron OS Licence Agreement on behalf of the owner of this appliance.</span>
            </label>
          </form>
        <?php else: ?>
          <p class="eula-note">Only an administrator can accept the agreement for this appliance. Ask one to sign in and accept it, then sign in again.</p>
        <?php endif; ?>

        <div class="eula-actions">
          <form method="post" action="<?= base_url('eula/decline') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost btn-lg"><?= $canAccept ? 'Decline' : 'Sign out' ?></button>
          </form>
          <?php if ($canAccept): ?>
            <button type="submit" form="eulaAcceptForm" class="btn btn-primary btn-lg" id="eulaAcceptBtn" disabled>Accept</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($canAccept): ?>
  <script>
    (function () {
      var box = document.getElementById('eulaAgree');
      var btn = document.getElementById('eulaAcceptBtn');
      box.addEventListener('change', function () { btn.disabled = !box.checked; });
    })();
  </script>
  <?php endif; ?>
<?= view('partials/app_footer', ['footerVariant' => 'standalone']) ?>
</body>

</html>
