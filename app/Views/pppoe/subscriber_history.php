<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$fmtDuration = static function (int $sec): string {
    if ($sec <= 0) return '0s';
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    if ($h > 0) return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    if ($m > 0) return $s > 0 ? "{$m}m {$s}s" : "{$m}m";
    return "{$s}s";
};
?>

<!-- ── Breadcrumb & Page Header ── -->
<div class="page-header" style="margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap;">
    <div>
      <div style="display:flex; align-items:center; gap:8px; font-size:0.75rem; color:var(--text-muted); margin-bottom:4px;">
        <a href="<?= base_url('pppoe') ?>" style="color:var(--text-muted); text-decoration:none;">PPPoE Server</a>
        <span>/</span>
        <span>Subscribers</span>
        <span>/</span>
        <span style="color:var(--text-primary); font-weight:600;"><?= esc($username) ?></span>
        <span>/</span>
        <span>Session History</span>
      </div>
      <h1 class="page-title" style="display:flex; align-items:center; gap:10px;">
        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        Subscriber Session History: <?= esc($username) ?>
      </h1>
      <p class="page-subtitle" style="font-size:0.82rem; color:var(--text-secondary); margin-top:2px;">
        Chronological audit of sessions and RADIUS reply attributes received upon each authentication, tracking rate limit and policy changes over time.
      </p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
      <a href="<?= base_url('pppoe') ?>" class="btn btn-ghost btn-sm" style="display:inline-flex; align-items:center; gap:6px;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back to PPPoE Server
      </a>
      <button type="button" class="btn btn-outline-primary btn-sm" onclick="window.location.reload()" style="display:inline-flex; align-items:center; gap:6px;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        Refresh History
      </button>
    </div>
  </div>
</div>

<!-- ── Subscriber Profile Card ── -->
<div class="card card-glass animate-in animate-in-delay-1" style="margin-bottom:20px; border-left:4px solid var(--color-cyan);">
  <div class="card-body" style="padding:18px 22px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
      <div style="display:flex; align-items:center; gap:14px;">
        <div style="width:44px; height:44px; border-radius:10px; background:rgba(6,182,212,0.12); display:flex; align-items:center; justify-content:center; color:var(--color-cyan); font-weight:700; font-size:1.1rem; border:1px solid rgba(6,182,212,0.25);">
          <?= strtoupper(substr($username, 0, 2)) ?>
        </div>
        <div>
          <div style="display:flex; align-items:center; gap:8px;">
            <h2 style="font-size:1.05rem; font-weight:700; margin:0; color:var(--text-primary); font-family:var(--font-mono);"><?= esc($username) ?></h2>
            <?php if (!empty($user['enabled']) && (int) $user['enabled'] === 1): ?>
              <span class="badge badge-success" style="font-size:0.65rem;">ACCOUNT ACTIVE</span>
            <?php else: ?>
              <span class="badge badge-muted" style="font-size:0.65rem;">DISABLED</span>
            <?php endif; ?>
            <?php if (!empty($metrics['active_sessions'])): ?>
              <span class="badge badge-cyan" style="font-size:0.65rem; display:inline-flex; align-items:center; gap:4px;">
                <span class="pulse-dot"></span> LIVE ONLINE
              </span>
            <?php endif; ?>
          </div>
          <div style="font-size:0.78rem; color:var(--text-muted); margin-top:3px;">
            Server: <strong style="color:var(--text-secondary);"><?= esc($server['name'] ?? 'Default Concentrator') ?></strong>
            <?php if (!empty($server['interface'])): ?> (<?= esc($server['interface']) ?>)<?php endif; ?>
            &bull; Static IP: <code style="color:var(--text-secondary);"><?= esc($user['static_ip'] ?? 'Dynamic Pool') ?></code>
            <?php if (!empty($user['description'])): ?> &bull; <?= esc($user['description']) ?><?php endif; ?>
          </div>
        </div>
      </div>
      <div style="display:flex; gap:10px; align-items:center;">
        <div style="text-align:right;">
          <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.04em;">Active Plan / Rate Limit</div>
          <div style="font-family:var(--font-mono); font-size:0.95rem; font-weight:700; color:var(--color-amber);">
            ⚡ <?= esc($metrics['current_rate_limit']) ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Metrics Grid ── -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:22px;">
  <div class="card card-glass animate-in animate-in-delay-1" style="padding:16px;">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Total Recorded Logins</div>
    <div style="font-size:1.45rem; font-weight:700; color:var(--text-primary); margin-top:4px; font-family:var(--font-mono);"><?= (int) $metrics['total_sessions'] ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">
      <?= (int) $metrics['active_sessions'] ?> active session now
    </div>
  </div>

  <div class="card card-glass animate-in animate-in-delay-1" style="padding:16px;">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Cumulative Traffic</div>
    <div style="font-size:1.45rem; font-weight:700; color:var(--color-cyan); margin-top:4px; font-family:var(--font-mono);"><?= esc($metrics['total_bytes_human']) ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px; font-family:var(--font-mono);">
      &darr;<?= number_format(($metrics['total_rx_bytes'] ?? 0) / 1048576, 1) ?> MB &nbsp;&uarr;<?= number_format(($metrics['total_tx_bytes'] ?? 0) / 1048576, 1) ?> MB
    </div>
  </div>

  <div class="card card-glass animate-in animate-in-delay-1" style="padding:16px;">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Cumulative Connected Time</div>
    <div style="font-size:1.45rem; font-weight:700; color:var(--text-primary); margin-top:4px; font-family:var(--font-mono);"><?= esc($metrics['total_uptime_human']) ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">
      Across all PPPoE sessions
    </div>
  </div>

  <div class="card card-glass animate-in animate-in-delay-1" style="padding:16px;">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Limit Policy Changes</div>
    <div style="font-size:1.45rem; font-weight:700; color:var(--color-amber); margin-top:4px; font-family:var(--font-mono);"><?= (int) $metrics['total_limit_changes'] ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">
      From initial: <code style="color:var(--text-secondary); font-size:0.72rem;"><?= esc($metrics['first_rate_limit']) ?></code>
    </div>
  </div>
</div>

<!-- ── Limit Changes Evolution Banner ── -->
<?php if (!empty($metrics['total_limit_changes']) && $metrics['total_limit_changes'] > 0): ?>
  <div class="card animate-in animate-in-delay-2" style="margin-bottom:20px; padding:14px 18px; background:rgba(245, 158, 11, 0.08); border:1px solid rgba(245, 158, 11, 0.3); border-radius:var(--radius-md);">
    <div style="display:flex; align-items:center; gap:10px;">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber); flex-shrink:0;">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
      </svg>
      <div style="font-size:0.82rem; color:var(--text-primary);">
        <strong>Rate Limit Progression Detected:</strong> This subscriber has had <strong><?= (int) $metrics['total_limit_changes'] ?> policy modification(s)</strong> over time. Look for the highlighted <span class="badge badge-amber" style="font-size:0.65rem; vertical-align:1px;">LIMIT CHANGED</span> badges in the session log below to trace bandwidth tier and session timeout modifications between logins.
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- ── Session History Table ── -->
<div class="card card-glass animate-in animate-in-delay-2">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
    <span class="card-title" style="display:flex; align-items:center; gap:10px;">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
      </svg>
      Login Sessions & RADIUS Reply Attributes
    </span>
    <span style="font-size:0.75rem; color:var(--text-muted);">
      Showing <?= count($history) ?> session(s) &bull; Newest logins first
    </span>
  </div>

  <div class="card-body" style="padding:0;">
    <?php if (empty($history)): ?>
      <div style="padding:36px; text-align:center; color:var(--text-muted); font-size:0.84rem;">
        No session history recorded for subscriber <strong><?= esc($username) ?></strong> yet.
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="iface-table" style="width:100%; border-collapse:collapse;">
          <thead>
            <tr>
              <th style="min-width:140px;">Login / Timestamp</th>
              <th>Status / Cause</th>
              <th>Interface & IP</th>
              <th>Duration</th>
              <th>Traffic</th>
              <th style="min-width:280px;">RADIUS Limits & Reply Attributes</th>
              <th style="text-align:right;">Attributes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($history as $idx => $s): ?>
              <tr style="<?= !empty($s['has_limit_change']) ? 'background:rgba(245, 158, 11, 0.04);' : '' ?>">
                <!-- Login Date / Time -->
                <td>
                  <div style="font-size:0.82rem; font-weight:600; color:var(--text-primary);">
                    <?= esc($s['login_at']) ?>
                  </div>
                  <div style="font-size:0.7rem; color:var(--text-muted); font-family:var(--font-mono);">
                    <?= !empty($s['logout_at']) ? 'Ended: ' . esc($s['logout_at']) : 'Currently connected' ?>
                  </div>
                </td>

                <!-- Status / Cause -->
                <td>
                  <?php if (!empty($s['is_active'])): ?>
                    <span class="badge badge-success" style="font-size:0.68rem; display:inline-flex; align-items:center; gap:4px;">
                      <span class="pulse-dot"></span> ACTIVE
                    </span>
                  <?php elseif ($s['terminate_cause'] === 'Session-Timeout'): ?>
                    <span class="badge badge-amber" style="font-size:0.68rem;">TIMEOUT</span>
                  <?php elseif ($s['terminate_cause'] === 'Lost-Carrier'): ?>
                    <span class="badge badge-danger" style="font-size:0.68rem;">LOST CARRIER</span>
                  <?php else: ?>
                    <span class="badge badge-muted" style="font-size:0.68rem;"><?= esc($s['terminate_cause'] ?: 'DISCONNECTED') ?></span>
                  <?php endif; ?>
                </td>

                <!-- Interface & IP -->
                <td style="font-family:var(--font-mono); font-size:0.8rem;">
                  <div><?= esc($s['interface'] ?: 'ppp?') ?></div>
                  <div style="font-size:0.72rem; color:var(--text-secondary);"><?= esc($s['assigned_ip'] ?: '—') ?></div>
                </td>

                <!-- Duration -->
                <td style="font-size:0.8rem; color:var(--text-secondary);">
                  <span class="badge badge-muted" style="font-family:var(--font-mono); font-size:0.7rem;">
                    <?= esc($s['uptime_human']) ?>
                  </span>
                </td>

                <!-- Traffic -->
                <td style="font-size:0.75rem; color:var(--text-muted); font-family:var(--font-mono); white-space:nowrap;">
                  <div>&darr; <?= esc($s['rx_human']) ?></div>
                  <div>&uarr; <?= esc($s['tx_human']) ?></div>
                </td>

                <!-- RADIUS Limits & Changes -->
                <td>
                  <div style="display:flex; flex-direction:column; gap:6px;">
                    <!-- Rate limit & change banner -->
                    <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                      <?php if (!empty($s['rate_limit'])): ?>
                        <span style="font-family:var(--font-mono); font-size:0.78rem; font-weight:700; color:var(--color-amber); background:rgba(245, 158, 11, 0.1); border:1px solid rgba(245, 158, 11, 0.3); border-radius:4px; padding:2px 8px;">
                          ⚡ <?= esc($s['rate_limit']) ?>
                        </span>
                      <?php else: ?>
                        <span style="font-size:0.72rem; color:var(--text-muted);">No rate limit</span>
                      <?php endif; ?>

                      <?php if (!empty($s['has_limit_change'])): ?>
                        <?php foreach ($s['changes'] as $chg): ?>
                          <span class="badge <?= esc($chg['badge']) ?>" style="font-size:0.65rem; letter-spacing:0.02em;" title="<?= esc($chg['label']) ?> changed from <?= esc($chg['old']) ?> to <?= esc($chg['new']) ?>">
                            <?= esc($chg['label']) ?>: <?= esc($chg['summary']) ?>
                          </span>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>

                    <!-- Additional RADIUS parameters -->
                    <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                      <?php if (!empty($s['session_timeout'])): ?>
                        <span style="font-size:0.7rem; color:var(--text-secondary); background:var(--bg-tertiary); border:1px solid var(--border-subtle); border-radius:4px; padding:1px 6px; font-family:var(--font-mono);" title="Session-Timeout">
                          Timeout: <?= esc($fmtDuration((int)$s['session_timeout'])) ?>
                        </span>
                      <?php endif; ?>

                      <?php if (!empty($s['idle_timeout'])): ?>
                        <span style="font-size:0.7rem; color:var(--text-secondary); background:var(--bg-tertiary); border:1px solid var(--border-subtle); border-radius:4px; padding:1px 6px; font-family:var(--font-mono);" title="Idle-Timeout">
                          Idle: <?= esc($fmtDuration((int)$s['idle_timeout'])) ?>
                        </span>
                      <?php endif; ?>

                      <?php if (!empty($s['interim_interval'])): ?>
                        <span style="font-size:0.7rem; color:var(--text-secondary); background:var(--bg-tertiary); border:1px solid var(--border-subtle); border-radius:4px; padding:1px 6px; font-family:var(--font-mono);" title="Acct-Interim-Interval">
                          Interim: <?= esc($fmtDuration((int)$s['interim_interval'])) ?>
                        </span>
                      <?php endif; ?>

                      <?php if (!empty($s['framed_ip']) && $s['framed_ip'] !== $s['assigned_ip']): ?>
                        <span style="font-size:0.7rem; color:var(--text-secondary); background:var(--bg-tertiary); border:1px solid var(--border-subtle); border-radius:4px; padding:1px 6px; font-family:var(--font-mono);" title="Framed-IP-Address">
                          Framed: <?= esc($s['framed_ip']) ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>

                <!-- View Raw Attributes Action -->
                <td style="text-align:right; white-space:nowrap;">
                  <button type="button" class="btn btn-ghost btn-sm" onclick="toggleAttrDrawer('attrDrawer-<?= $idx ?>')" style="font-size:0.72rem; padding:3px 8px;">
                    View Raw
                  </button>
                </td>
              </tr>

              <!-- Expandable Raw RADIUS Attributes Drawer -->
              <tr id="attrDrawer-<?= $idx ?>" style="display:none; background:var(--color-surface-2);">
                <td colspan="7" style="padding:12px 18px; border-top:1px dashed var(--color-border);">
                  <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                    <div style="font-size:0.75rem; font-weight:600; color:var(--color-cyan); text-transform:uppercase; letter-spacing:0.04em;">
                      RADIUS Reply Attributes for Session <?= esc($s['session_id'] ?: '#' . ($idx + 1)) ?>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="toggleAttrDrawer('attrDrawer-<?= $idx ?>')" style="font-size:0.7rem; padding:1px 6px;">
                      Close &times;
                    </button>
                  </div>
                  <?php if (empty($s['raw_attributes'])): ?>
                    <div style="font-size:0.75rem; color:var(--text-muted); font-style:italic;">No raw vendor attributes stored for this session.</div>
                  <?php else: ?>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:8px;">
                      <?php foreach ($s['raw_attributes'] as $attrKey => $attrVal): ?>
                        <div style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:4px; padding:6px 10px; font-family:var(--font-mono); font-size:0.75rem;">
                          <span style="color:var(--text-secondary);"><?= esc($attrKey) ?></span>:
                          <strong style="color:var(--text-primary);"><?= esc($attrVal) ?></strong>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
function toggleAttrDrawer(id) {
  var el = document.getElementById(id);
  if (!el) return;
  el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'table-row' : 'none';
}
</script>

<?= $this->endSection() ?>
