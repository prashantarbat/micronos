<?php
/**
 * Flash messages after a redirect.
 *
 * Messages are escaped: they routinely embed operator-supplied names (a
 * gateway, a tunnel, a certificate), and printing them raw made every such
 * name a stored script injection point. Four levels; errors are announced
 * assertively, the rest politely. Each can be dismissed.
 */
$_levels = [
    'error'   => ['alert-error',   'alert',  'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    'warning' => ['alert-warning', 'alert',  'M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z'],
    'success' => ['alert-success', 'status', 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
    'info'    => ['alert-info',    'status', 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
];
?>
<div class="flash-stack">
<?php foreach ($_levels as $_key => [$_class, $_role, $_icon]): ?>
  <?php if (session()->has($_key) && is_scalar(session($_key)) && (string) session($_key) !== ''): ?>
    <div class="alert <?= $_class ?> animate-in" role="<?= $_role ?>" data-flash="<?= $_key ?>">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $_icon ?>"/></svg>
      <span class="alert-text"><?= esc((string) session($_key)) ?></span>
      <button type="button" class="alert-dismiss" aria-label="Dismiss message" onclick="this.closest('.alert').remove()">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
  <?php endif; ?>
<?php endforeach; ?>
</div>
