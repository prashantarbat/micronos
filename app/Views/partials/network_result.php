<?php
/**
 * What the last network transaction changed, and — when a change is waiting
 * for confirmation — the Confirm / Roll back controls.
 *
 * Fed by the `net_result` flash that Interfaces::commitNetwork() sets, so it
 * shows exactly what the daemon and AddressChangeSync reported.
 */
$__r = session()->getFlashdata('net_result');
if (! is_array($__r)) {
    return;
}
$__tx = (string) ($__r['tx'] ?? '');
?>
<div class="card animate-in net-result" style="margin-bottom:20px; padding:14px 18px; border-left:3px solid <?= $__r['status'] === 'pending_confirm' ? '#f59e0b' : (in_array($__r['status'], ['applied'], true) ? '#10b981' : '#ef4444') ?>;">
  <?php if ($__r['status'] === 'pending_confirm' && $__tx !== ''): ?>
    <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; margin-bottom:8px;">
      <div>
        <strong>This change is live but not yet committed.</strong>
        <div style="font-size:.8rem; color:var(--text-muted);">
          Confirm it within <span id="netConfirmLeft"><?= (int) \App\Libraries\Network\NetworkTransaction::CONFIRM_SECONDS ?></span> s,
          from the new address if the management address changed, or it is rolled back automatically.
        </div>
      </div>
      <div style="display:flex; gap:8px;">
        <form method="post" action="<?= base_url('interfaces/network/confirm') ?>" style="margin:0;">
          <?= csrf_field() ?><input type="hidden" name="tx" value="<?= esc($__tx) ?>">
          <button type="submit" class="btn btn-success btn-sm">Confirm change</button>
        </form>
        <form method="post" action="<?= base_url('interfaces/network/rollback') ?>" style="margin:0;">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost btn-sm">Roll back now</button>
        </form>
      </div>
    </div>
    <script>
      (function () {
        var el = document.getElementById('netConfirmLeft'); if (!el) return;
        var left = parseInt(el.textContent, 10);
        var t = setInterval(function () { left--; el.textContent = Math.max(0, left); if (left <= 0) clearInterval(t); }, 1000);
      })();
    </script>
  <?php endif; ?>

  <?php if (! empty($__r['errors'])): ?>
    <div style="font-size:.8rem; font-weight:700; margin-bottom:4px;">Refused</div>
    <ul class="net-list"><?php foreach ($__r['errors'] as $__e): ?><li><?= esc((string) $__e) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <?php if (! empty($__r['updated'])): ?>
    <div style="font-size:.8rem; font-weight:700; margin:6px 0 4px;">Dependent settings updated</div>
    <ul class="net-list">
      <?php foreach ($__r['updated'] as $__u): ?>
        <li><?= esc((string) $__u['what']) ?>: <code><?= esc((string) $__u['from']) ?></code> → <code><?= esc((string) $__u['to']) ?></code></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if (! empty($__r['review'])): ?>
    <div style="font-size:.8rem; font-weight:700; margin:6px 0 4px; color:#f59e0b;">Needs your review (left unchanged)</div>
    <ul class="net-list">
      <?php foreach ($__r['review'] as $__u): ?>
        <li><?= esc((string) $__u['what']) ?> <code><?= esc((string) $__u['value']) ?></code> — <?= esc((string) $__u['reason']) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if (! empty($__r['warnings'])): ?>
    <div style="font-size:.8rem; font-weight:700; margin:6px 0 4px;">Warnings</div>
    <ul class="net-list"><?php foreach ($__r['warnings'] as $__w): ?><li><?= esc((string) $__w) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</div>
<style>.net-list{margin:0;padding-left:18px;font-size:.78rem;color:var(--text-secondary);}.net-list code{font-family:var(--font-mono);font-size:.75rem;}</style>
