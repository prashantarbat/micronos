<?php
/**
 * Live PPPoE session table body with link-state indicator and redial action.
 *
 * Rendered both inside the PPPoE page and on its own for the monitor's refresh
 * and redial responses, so the two can never drift apart.
 *
 * @var list<array> $sessions
 * @var array       $linkHealth
 */
$linkHealth = $linkHealth ?? [];
$clientLinks = $linkHealth['links'] ?? [];

// Gather devices already represented in active client sessions
$activeClientDevices = [];
foreach ($sessions as $s) {
    if (($s['role'] ?? '') === 'client' && !empty($s['device'])) {
        $activeClientDevices[(string) $s['device']] = true;
    }
}

// Any configured client interface that has no active session row is shown as a disconnected/backed-off link
$disconnectedClientLinks = [];
foreach ($clientLinks as $device => $lh) {
    if (!isset($activeClientDevices[$device])) {
        $disconnectedClientLinks[] = $lh;
    }
}
?>

<?php if (!empty($linkHealth['backed_off'])): ?>
  <div class="alert animate-in" style="margin-bottom:14px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; border-radius:var(--radius-md); font-size:0.8rem; background:rgba(245, 158, 11, 0.1); border:1px solid rgba(245, 158, 11, 0.35); color:var(--text-primary);">
    <div style="display:flex; align-items:center; gap:8px;">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber); flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
      <span>
        <strong>Escalating Backoff Active:</strong>
        <?php foreach ($clientLinks as $lh): ?>
          <?php if (!empty($lh['is_backed_off'])): ?>
            Link <code><?= esc($lh['name'] ?: $lh['interface']) ?></code> failed <?= (int) $lh['failure_count'] ?> time(s). Retrying less aggressively; next retry in <strong><?= esc($lh['remaining_human'] ?: 'soon') ?></strong> (<?= esc($lh['next_retry_at']) ?>).
          <?php endif; ?>
        <?php endforeach; ?>
      </span>
    </div>
    <button type="button" class="btn btn-warning btn-sm" onclick="redialNow()" style="white-space:nowrap; font-size:0.75rem; padding:3px 10px;">
      Redial now (Bypass backoff)
    </button>
  </div>
<?php endif; ?>

<?php if (empty($sessions) && empty($disconnectedClientLinks)): ?>
  <div style="padding:28px; text-align:center; color:var(--text-muted); font-size:0.82rem;">
    No active PPPoE client links or subscriber sessions.
  </div>
<?php else: ?>
  <div class="table-responsive">
    <table class="iface-table" style="width:100%;">
      <thead>
        <tr>
          <th>Session / Device</th>
          <th>Role</th>
          <th>Subscriber / ISP</th>
          <th>Address</th>
          <th>Peer</th>
          <th>Uptime</th>
          <th>Traffic</th>
          <th>Link State</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sessions as $s): ?>
          <tr>
            <td style="font-family:var(--font-mono); font-size:0.82rem; color:var(--text-primary);">
              <?= esc($s['interface'] ?: 'ppp?') ?>
              <span style="color:var(--text-muted); font-size:0.7rem;">#<?= (int) ($s['pid'] ?? 0) ?></span>
              <?php if (!empty($s['device'])): ?>
                <span style="color:var(--text-muted); font-size:0.7rem;">(<?= esc($s['device']) ?>)</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($s['role'] === 'client'): ?>
                <span class="badge badge-cyan" style="font-size:0.68rem;">CLIENT WAN</span>
              <?php else: ?>
                <span class="badge badge-success" style="font-size:0.68rem;">SUBSCRIBER</span>
              <?php endif; ?>
            </td>
            <td style="font-size:0.8rem; color:var(--text-secondary);">
              <?php if (!empty($s['username'])): ?>
                <a href="<?= base_url('pppoe/users/' . urlencode($s['subscriber_id'] ?? $s['username']) . '/history') ?>"
                   style="font-weight:600; color:var(--color-primary); text-decoration:none;"
                   title="View RADIUS attribute and limit change history">
                  <?= esc($s['username']) ?>
                </a>
                <span style="color:var(--text-muted); font-size:0.72rem;">@ <?= esc($s['server']) ?></span>
              <?php elseif ($s['role'] === 'client'): ?>
                <span style="color:var(--text-secondary); font-family:var(--font-mono); font-size:0.75rem;">ISP Gateway</span>
              <?php else: ?>
                <span style="color:var(--text-muted);">—</span>
              <?php endif; ?>
            </td>
            <td style="font-family:var(--font-mono); font-size:0.8rem;"><?= esc($s['local_ip'] ?: '—') ?></td>
            <td style="font-family:var(--font-mono); font-size:0.8rem;"><?= esc($s['peer_ip'] ?: '—') ?></td>
            <td style="font-size:0.8rem; color:var(--text-secondary);"><?= esc($s['uptime_human']) ?></td>
            <td style="font-size:0.75rem; color:var(--text-muted); font-family:var(--font-mono);">
              &darr;<?= esc($s['rx_human']) ?> &nbsp; &uarr;<?= esc($s['tx_human']) ?>
            </td>
            <td>
              <?php if ($s['role'] === 'client'): ?>
                <?php
                  $dev = (string) ($s['device'] ?? '');
                  $lh = $clientLinks[$dev] ?? null;
                  $stateClass = ($lh && $lh['is_backed_off']) ? 'badge-warning' : ($s['is_up'] ? 'badge-success' : 'badge-danger');
                  $stateText  = ($lh && $lh['is_backed_off']) ? 'BACKOFF' : ($s['is_up'] ? 'UP' : ($s['state'] ?: 'DOWN'));
                ?>
                <span class="badge <?= $stateClass ?>" style="font-size:0.68rem; display:inline-flex; align-items:center; gap:5px;" title="<?= esc($lh['status_tooltip'] ?? '') ?>">
                  <span class="<?= $s['is_up'] ? 'pulse-dot' : 'status-dot dot-' . (($lh && $lh['is_backed_off']) ? 'warning' : 'danger') ?>"></span>
                  <?= esc($stateText) ?>
                </span>
              <?php else: ?>
                <?php if ($s['is_up']): ?>
                  <span class="badge badge-success" style="font-size:0.68rem; display:inline-flex; align-items:center; gap:5px;">
                    <span class="pulse-dot"></span> UP
                  </span>
                <?php else: ?>
                  <span class="badge badge-muted" style="font-size:0.68rem;"><?= esc($s['state'] ?: 'UNKNOWN') ?></span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td style="text-align:right;">
              <?php if ($s['role'] === 'client'): ?>
                <button type="button" class="btn btn-ghost btn-sm" onclick="redialNow('<?= esc($s['device'] ?? '', 'js') ?>')" style="font-size:0.72rem; padding:2px 8px;" title="Redial this link immediately">
                  Redial
                </button>
              <?php elseif (!empty($s['username'])): ?>
                <a href="<?= base_url('pppoe/users/' . urlencode($s['subscriber_id'] ?? $s['username']) . '/history') ?>" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:2px 8px;" title="View session & limit history">
                  History
                </a>
              <?php endif; ?>
            </td>
          </tr>
          <?php if (!empty($s['radius_chips']) || !empty($s['radius_others'])): ?>
            <?php
              $rawTitle = implode(", ", array_merge(
                  $s['radius_chips'] ?? [],
                  $s['radius_others'] ?? [],
              ));
            ?>
            <tr class="session-radius-row">
              <td colspan="9" style="padding:6px 12px 8px; background:rgba(6,182,212,0.05); border-left:2px solid var(--color-cyan);">
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;" title="<?= esc($rawTitle) ?>">
                  <span class="badge badge-cyan" style="font-size:0.6rem; letter-spacing:0.04em;">RADIUS LIMITS</span>
                  <?php foreach ($s['radius_chips'] as $chip): ?>
                    <span style="font-size:0.72rem; color:var(--text-secondary); background:var(--bg-tertiary); border:1px solid var(--border-subtle); border-radius:4px; padding:1px 7px; font-family:var(--font-mono);">
                      <?= esc($chip) ?>
                    </span>
                  <?php endforeach; ?>
                  <?php if (empty($s['radius_chips']) && empty($s['radius_others'])): ?>
                    <span style="font-size:0.72rem; color:var(--text-muted);">attributes recorded</span>
                  <?php endif; ?>
                  <?php if (count($s['radius_others']) > 0): ?>
                    <span style="font-size:0.68rem; color:var(--text-muted);" title="<?= esc(implode(", ", $s['radius_others'])) ?>">
                      +<?= count($s['radius_others']) ?> more
                    </span>
                  <?php endif; ?>
                  <?php if (!empty($s['username'])): ?>
                    <a href="<?= base_url('pppoe/users/' . urlencode($s['subscriber_id'] ?? $s['username']) . '/history') ?>" style="font-size:0.7rem; color:var(--color-cyan); margin-left:auto; text-decoration:none;">
                      View history &rarr;
                    </a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php foreach ($disconnectedClientLinks as $dcl): ?>
          <tr style="background:rgba(239, 68, 68, 0.03);">
            <td style="font-family:var(--font-mono); font-size:0.82rem; color:var(--text-muted);">
              —
              <span style="color:var(--text-muted); font-size:0.7rem;">(<?= esc($dcl['interface']) ?>)</span>
            </td>
            <td>
              <span class="badge badge-cyan" style="font-size:0.68rem;">CLIENT WAN</span>
            </td>
            <td style="font-size:0.8rem; color:var(--text-secondary);">
              <strong><?= esc($dcl['name'] ?: $dcl['interface']) ?></strong>
              <span style="color:var(--text-muted); font-size:0.72rem;">(PPPoE Client)</span>
            </td>
            <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--text-muted);">—</td>
            <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--text-muted);">—</td>
            <td style="font-size:0.8rem; color:var(--text-muted);">Disconnected</td>
            <td style="font-size:0.75rem; color:var(--text-muted); font-family:var(--font-mono);">—</td>
            <td>
              <span class="badge <?= esc($dcl['badge_class']) ?>" style="font-size:0.68rem; display:inline-flex; align-items:center; gap:5px;" title="<?= esc($dcl['status_tooltip']) ?>">
                <span class="status-dot dot-<?= $dcl['is_backed_off'] ? 'warning' : 'danger' ?>"></span>
                <?= esc($dcl['state']) ?>
              </span>
            </td>
            <td style="text-align:right;">
              <button type="button" class="btn btn-primary btn-sm" onclick="redialNow('<?= esc($dcl['interface'], 'js') ?>')" style="font-size:0.72rem; padding:2px 8px;">
                Redial now
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
