<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex">

    <title><?= lang('Errors.whoops') ?></title>

    <style>
        <?= preg_replace('#[\r\n\t ]+#', ' ', file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'debug.css')) ?>
    </style>
</head>
<body>

    <div class="container text-center">

        <h1 class="headline"><?= lang('Errors.whoops') ?></h1>

        <p class="lead"><?= lang('Errors.weHitASnag') ?></p>

    </div>

<?php
// Shared attribution footer. Guarded: an error page must render even when
// the framework is too broken to build a view.
try {
    echo view('partials/app_footer', ['footerVariant' => 'standalone']);
} catch (\Throwable $e) {
    echo '<footer class="app-footer" role="contentinfo">Micron OS · Author: Prashant Arbat · <a href="https://github.com/prashantarbat/micronos">https://github.com/prashantarbat/micronos</a></footer>';
}
?>
<style>
  .app-footer { font: 12px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: #8a94a6; text-align: center; padding: 18px 16px; }
  .app-footer a { color: #38bdf8; }
  .app-footer__sep { margin: 0 6px; opacity: .6; }
  .app-footer__inner { display: flex; flex-wrap: wrap; gap: 4px 10px; justify-content: center; }
</style>
</body>

</html>
