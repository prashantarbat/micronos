<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.cron-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 24px;
  border-bottom: 1px solid var(--color-border);
  padding-bottom: 12px;
  flex-wrap: wrap;
}
.cron-tab {
  padding: 8px 16px;
  border-radius: var(--radius-md);
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--text-secondary);
  background: rgba(255,255,255,0.02);
  border: 1px solid var(--color-border);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all var(--transition-fast);
}
.cron-tab:hover {
  color: var(--text-primary);
  background: rgba(255,255,255,0.06);
  border-color: var(--color-border-bright);
}
.cron-tab.active {
  background: var(--color-cyan-dim);
  color: var(--color-cyan);
  border-color: var(--color-cyan);
  font-weight: 700;
}
.cron-panel { display: none; }
.cron-panel.active { display: block; }

.cron-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.cron-badge {
  font-family: var(--font-mono);
  font-size: 0.75rem;
  padding: 3px 8px;
  border-radius: var(--radius-sm);
  background: rgba(255,255,255,0.05);
  color: var(--color-cyan);
  border: 1px solid var(--color-border);
}
.log-terminal {
  background: #090d16;
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: var(--radius-md);
  font-family: var(--font-mono, monospace);
  font-size: 0.78rem;
  padding: 14px;
  max-height: 480px;
  overflow-y: auto;
  color: #d1d5db;
  line-height: 1.55;
}
</style>

<!-- Header -->
<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="display:flex; align-items:center; gap:10px; margin-bottom:4px;">
        <span style="display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:8px; background:rgba(14,165,233,0.15); border:1px solid rgba(14,165,233,0.3); color:var(--color-cyan);">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
          </svg>
        </span>
        <h1 class="page-title" style="margin:0;">Cron &amp; Scheduled Tasks</h1>
      </div>
      <p class="page-subtitle">
        Automate system maintenance, configuration snapshots, DNSBL threat updates, log rotations, and certificate renewals
      </p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <a href="<?= base_url('services') ?>" class="btn btn-ghost btn-sm">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Services Status
      </a>
      <button type="button" class="btn btn-ghost btn-sm" onclick="switchCronTab('logs', document.getElementById('tab-cron-logs-btn'))">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/>
        </svg>
        Execution Logs
      </button>
      <button type="button" class="btn btn-primary btn-sm" onclick="openAddCronModal()">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
        </svg>
        Add Scheduled Task
      </button>
    </div>
  </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:20px;">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
    <div><?= session()->getFlashdata('success') ?></div>
  </div>
<?php endif; ?>

<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-error animate-in" style="margin-bottom:20px;">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
    <div><?= session()->getFlashdata('error') ?></div>
  </div>
<?php endif; ?>

<!-- Tabs Navigation -->
<div class="cron-tabs animate-in animate-in-delay-1">
  <button type="button" class="cron-tab active" id="tab-cron-tasks-btn" onclick="switchCronTab('tasks', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
    </svg>
    Scheduled Crontab Tasks
    <span class="badge badge-cyan" style="font-size:0.65rem; padding:1px 6px;"><?= count($jobs) ?></span>
  </button>
  <button type="button" class="cron-tab" id="tab-cron-logs-btn" onclick="switchCronTab('logs', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
    </svg>
    Execution History &amp; Logs
    <span class="badge badge-success" style="font-size:0.65rem; padding:1px 6px;">Live</span>
  </button>
</div>

<!-- ============================================================
     TAB 1: Scheduled Tasks Table
============================================================ -->
<div class="cron-panel active" id="panel-cron-tasks">
  
  <!-- Summary Cards -->
  <div class="cron-stats-grid animate-in animate-in-delay-1">
    <div class="stat-card cyan">
      <div class="stat-icon cyan">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
        </svg>
      </div>
      <div class="stat-value"><?= $totalCount ?></div>
      <div class="stat-label">Configured Tasks</div>
      <div class="stat-sublabel">Total cron jobs in /etc/cron.d</div>
    </div>

    <div class="stat-card emerald">
      <div class="stat-icon emerald">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
      </div>
      <div class="stat-value" style="color:var(--color-emerald);"><?= $activeCount ?></div>
      <div class="stat-label">Active / Scheduled</div>
      <div class="stat-sublabel">Daemon cron scheduler active</div>
    </div>

    <div class="stat-card purple">
      <div class="stat-icon purple">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
        </svg>
      </div>
      <div class="stat-value" style="color:var(--color-purple);">Automated</div>
      <div class="stat-label">Backups &amp; Threat Updates</div>
      <div class="stat-sublabel">Zero manual intervention</div>
    </div>
  </div>

  <!-- Scheduled Tasks Table -->
  <div class="card card-glass animate-in animate-in-delay-2">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <div class="card-title" style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
        </svg>
        Scheduled Crontab Tasks
      </div>
      <span class="badge badge-cyan" style="font-size:0.75rem;"><?= count($jobs) ?> Tasks</span>
    </div>

    <div style="overflow-x:auto;">
      <table class="iface-table">
        <thead>
          <tr>
            <th>Status</th>
            <th>Schedule (Crontab)</th>
            <th>Description</th>
            <th>Command</th>
            <th>User</th>
            <th>Last Run</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($jobs)): ?>
            <tr>
              <td colspan="7" style="text-align:center; padding:32px; color:var(--text-muted);">
                No scheduled cron tasks configured. Click "+ Add Scheduled Task" to create one.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($jobs as $job): ?>
              <tr>
                <!-- Status / Toggle -->
                <td>
                  <form action="<?= base_url('cron/' . esc($job['id']) . '/toggle') ?>" method="POST" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:2px 8px; font-size:0.68rem;" title="Toggle Enabled/Disabled">
                      <?php if (!empty($job['enabled'])): ?>
                        <span class="badge badge-success">ACTIVE</span>
                      <?php else: ?>
                        <span class="badge badge-muted">DISABLED</span>
                      <?php endif; ?>
                    </button>
                  </form>
                </td>

                <!-- Crontab Schedule -->
                <td>
                  <span class="cron-badge">
                    <?= esc($job['minute']) ?> <?= esc($job['hour']) ?> <?= esc($job['day_of_month']) ?> <?= esc($job['month']) ?> <?= esc($job['day_of_week']) ?>
                  </span>
                  <div style="font-size:0.7rem; color:var(--text-muted); margin-top:3px;">
                    <?php
                      if ($job['minute'] === '0' && $job['hour'] === '2') echo 'Daily at 02:00 AM';
                      elseif ($job['minute'] === '30' && $job['hour'] === '3') echo 'Daily at 03:30 AM';
                      elseif ($job['minute'] === '0' && $job['hour'] === '0') echo 'Daily at Midnight';
                      elseif ($job['minute'] === '*/15') echo 'Every 15 minutes';
                      elseif ($job['minute'] === '*/5') echo 'Every 5 minutes';
                      else echo 'Custom schedule';
                    ?>
                  </div>
                </td>

                <!-- Description -->
                <td style="font-weight:600; color:var(--text-primary); font-size:0.85rem;">
                  <?= esc($job['description']) ?>
                </td>

                <!-- Command -->
                <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--color-cyan); max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= esc($job['command']) ?>">
                  <?= esc($job['command']) ?>
                </td>

                <!-- User -->
                <td>
                  <span class="badge badge-purple" style="font-size:0.7rem; font-family:var(--font-mono);">
                    <?= esc($job['user']) ?>
                  </span>
                </td>

                <!-- Last Run -->
                <td>
                  <?php if (!empty($job['last_run_at'])): ?>
                    <div style="font-size:0.75rem; color:var(--text-primary); font-family:var(--font-mono);">
                      <?= date('M d H:i', strtotime($job['last_run_at'])) ?>
                    </div>
                    <?php if (!empty($job['last_status'])): ?>
                      <span class="badge <?= $job['last_status'] === 'success' ? 'badge-success' : 'badge-error' ?>" style="font-size:0.6rem; padding:1px 5px;">
                        <?= strtoupper(esc($job['last_status'])) ?>
                      </span>
                    <?php endif; ?>
                    <?php if (!empty($job['last_output'])): ?>
                      <!-- The row's values are not here: the button carries which
                           row it is and which action it opens, and the page's
                           script reads the job out of the JSON island below. -->
                      <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.65rem; padding:1px 5px;" data-cron-action="output" data-cron-id="<?= esc($job['id'], 'attr') ?>">
                        Log
                      </button>
                    <?php endif; ?>
                  <?php else: ?>
                    <span style="font-size:0.75rem; color:var(--text-muted);">Never run</span>
                  <?php endif; ?>
                </td>

                <!-- Actions -->
                <td style="text-align:right;">
                  <div style="display:flex; justify-content:flex-end; gap:6px;">
                    
                    <!-- Run Now -->
                    <form action="<?= base_url('cron/' . esc($job['id']) . '/run-now') ?>" method="POST" style="display:inline;">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px;" title="Execute Immediately">
                        <svg width="12" height="12" fill="currentColor" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        Run
                      </button>
                    </form>

                    <!-- Edit -->
                    <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 8px;" data-cron-action="edit" data-cron-id="<?= esc($job['id'], 'attr') ?>" title="Edit Schedule">
                      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                      </svg>
                    </button>

                    <!-- Delete -->
                    <form action="<?= base_url('cron/' . esc($job['id']) . '/delete') ?>" method="POST" style="display:inline;" onsubmit="return confirm('Delete this scheduled task?');">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px; color:var(--color-rose);" title="Delete Task">
                        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                      </button>
                    </form>

                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <?php
        /**
         * The task rows' buttons carry only an id and an action. The values
         * they open — a description, the command, a run's output — travel in
         * this island, which is the one place a value off the wire is written
         * with anything other than the DOM's own escaping.
         *
         * The JSON is encoded for an HTML attribute *and* a JavaScript context
         * at once: every quote, angle bracket and ampersand becomes a `\uXXXX`
         * escape, so no description or run output can spell `</script>`, close
         * the element, or reach a parser as anything but data, whatever an
         * operator or the daemon put in it. That is more than `json_encode`
         * does on its own: it leaves `'` alone, and this JSON used to sit inside
         * a single-quoted `onclick` — where one apostrophe in a description ended
         * the attribute and the rest of the value was parsed as markup.
         */
        $cronJobs = array_map(static fn (array $job): array => [
            'id'           => $job['id'],
            'description'  => $job['description'] ?? '',
            'command'      => $job['command'] ?? '',
            'user'         => $job['user'] ?? '',
            'minute'       => $job['minute'] ?? '*',
            'hour'         => $job['hour'] ?? '*',
            'day_of_month' => $job['day_of_month'] ?? '*',
            'month'        => $job['month'] ?? '*',
            'day_of_week'  => $job['day_of_week'] ?? '*',
            'enabled'      => (int) ($job['enabled'] ?? 0),
            'last_output'  => $job['last_output'] ?? '',
        ], $jobs);
      ?>
      <script type="application/json" id="cronJobsData"><?= json_encode($cronJobs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
    </div>
  </div>
</div>

<!-- ============================================================
     TAB 2: Cron Execution History & Logs
============================================================ -->
<div class="cron-panel" id="panel-cron-logs">
  <div class="card card-glass animate-in animate-in-delay-2">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <span class="card-title">Recent Cron Task Execution Log</span>
      </div>
      <div style="display:flex; gap:8px;">
        <button type="button" class="btn btn-ghost btn-sm" onclick="fetchCronLogs()">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Refresh Logs
        </button>
      </div>
    </div>

    <div class="card-body" style="padding:0;">
      <div style="overflow-x:auto;">
        <table class="iface-table" style="margin:0;">
          <thead>
            <tr>
              <th style="width:140px;">Timestamp</th>
              <th>PID</th>
              <th>User</th>
              <th>Command Executed</th>
              <th>Duration</th>
              <th>Status</th>
              <th style="text-align:right;">Output</th>
            </tr>
          </thead>
          <tbody id="cronLogTableBody">
            <tr>
              <td colspan="7" style="text-align:center; padding:24px; color:var(--text-muted);">
                Loading execution history...
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Add / Edit Scheduled Cron Task -->
<div id="cronModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:620px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1.1rem; font-weight:700; display:flex; align-items:center; gap:8px;" id="cronModalTitle">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
        </svg>
        Add Scheduled Task
      </h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeCronModal()">&times;</button>
    </div>

    <form id="cronForm" method="POST" action="<?= base_url('cron/store') ?>">
      <?= csrf_field() ?>

      <!-- Quick Template Selector -->
      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label">Task Preset Template (Optional)</label>
        <select class="form-control" onchange="applyTaskPreset(this.value)">
          <option value="">-- Choose a standard appliance maintenance template --</option>
          <option value="backup">Nightly Configuration Snapshot (php spark backup:create)</option>
          <option value="dnsbl">Update DNSBL Threat Feeds (/usr/local/bin/dnsbl-update --reload-unbound)</option>
          <option value="logrotate">Midnight Log Rotation (/usr/sbin/logrotate /etc/logrotate.conf)</option>
          <option value="cert">Certificate Expiry Validation (/usr/local/bin/micron-cert-renew --check-expiry 30)</option>
          <option value="dyndns">Quarter-Hourly Dynamic DNS Check (/usr/local/bin/dyndns-update --iface eth0)</option>
          <option value="arp">Flush Stale ARP Cache (/usr/sbin/ip neigh flush all)</option>
          <option value="resolve">Refresh Address Object Feeds (php spark micron:resolve-objects)</option>
          <option value="servicesync">Sync Internet Service Database (php spark micron:sync-services --geo)</option>
          <option value="pppoeredial">PPPoE Link Reconnect &amp; Maintenance (redial down WAN links)</option>
        </select>
      </div>

      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label">Task Description</label>
        <input type="text" name="description" id="cron_desc" class="form-control" required placeholder="e.g. Nightly Configuration Snapshot">
      </div>

      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label">Command to Execute</label>
        <input type="text" name="command" id="cron_cmd" class="form-control" required placeholder="/usr/local/bin/my-script.sh" style="font-family:var(--font-mono);">
      </div>

      <!-- Schedule Preset Selector -->
      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label">Schedule Preset</label>
        <select class="form-control" onchange="applySchedulePreset(this.value)">
          <option value="custom">-- Custom Cron Expression --</option>
          <option value="every_5m">Every 5 Minutes (*/5 * * * *)</option>
          <option value="every_15m">Every 15 Minutes (*/15 * * * *)</option>
          <option value="every_hour">Every Hour at Minute 0 (0 * * * *)</option>
          <option value="daily_midnight">Daily at Midnight (0 0 * * *)</option>
          <option value="daily_2am" selected>Daily at 02:00 AM (0 2 * * *)</option>
          <option value="weekly_sunday">Weekly on Sunday at 00:00 (0 0 * * 0)</option>
          <option value="monthly_1st">Monthly on the 1st at 00:00 (0 0 1 * *)</option>
        </select>
      </div>

      <!-- 5-Field Granular Cron Grid -->
      <div style="display:grid; grid-template-columns:repeat(5, 1fr); gap:10px; margin-bottom:14px;">
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:0.7rem;">Minute</label>
          <input type="text" name="minute" id="cron_min" class="form-control" value="0" required style="font-family:var(--font-mono); text-align:center;">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:0.7rem;">Hour</label>
          <input type="text" name="hour" id="cron_hr" class="form-control" value="2" required style="font-family:var(--font-mono); text-align:center;">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:0.7rem;">Day of Month</label>
          <input type="text" name="day_of_month" id="cron_dom" class="form-control" value="*" required style="font-family:var(--font-mono); text-align:center;">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:0.7rem;">Month</label>
          <input type="text" name="month" id="cron_mon" class="form-control" value="*" required style="font-family:var(--font-mono); text-align:center;">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:0.7rem;">Day of Week</label>
          <input type="text" name="day_of_week" id="cron_dow" class="form-control" value="*" required style="font-family:var(--font-mono); text-align:center;">
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:18px;">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Run as User</label>
          <select name="user" id="cron_user" class="form-control">
            <option value="root">root (Privileged Appliance Administrator)</option>
            <option value="www-data">www-data (Unprivileged Web Process)</option>
            <option value="nobody">nobody (Restricted Sandbox)</option>
          </select>
        </div>

        <div class="form-group" style="margin:0; display:flex; align-items:center; padding-top:24px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; color:var(--text-primary);">
            <input type="checkbox" name="enabled" id="cron_enabled" value="1" checked style="accent-color:var(--color-cyan); width:16px; height:16px;">
            Enable Task Schedule
          </label>
        </div>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeCronModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="cronSubmitBtn">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save Scheduled Task
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: View Task Output -->
<div id="outputModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:680px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h3 style="margin:0; font-size:1.05rem; font-weight:700; display:flex; align-items:center; gap:8px;" id="outputModalTitle">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        Task Output
      </h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeOutputModal()">&times;</button>
    </div>
    
    <div style="background:#070a12; border:1px solid rgba(255,255,255,0.08); border-radius:var(--radius-md); padding:16px; font-family:var(--font-mono); font-size:0.78rem; color:#6ee7b7; max-height:360px; overflow-y:auto; line-height:1.6; white-space:pre-wrap;" id="outputModalBody">
    </div>

    <div style="display:flex; justify-content:flex-end; margin-top:16px;">
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeOutputModal()">Close</button>
    </div>
  </div>
</div>

<script>
let cronLogs = [];

function switchCronTab(tabId, btn) {
  document.querySelectorAll('.cron-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.cron-panel').forEach(p => p.classList.remove('active'));
  if (btn) btn.classList.add('active');
  const panel = document.getElementById('panel-cron-' + tabId);
  if (panel) panel.classList.add('active');

  if (tabId === 'logs') {
    fetchCronLogs();
  }
}

async function fetchCronLogs() {
  try {
    const res = await MicronScheduler.fetch('<?= base_url('cron/logs') ?>', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();
    cronLogs = data.entries || [];
    renderCronLogs(cronLogs);
  } catch (err) {
    console.error('Failed to fetch cron logs:', err);
  }
}

// The SVG namespace, and the cell styles the execution row builder uses.
const SVG_NS = 'http://www.w3.org/2000/svg';
const STYLE_STAMP   = 'font-family:var(--font-mono); font-size:0.72rem; color:var(--text-muted);';
const STYLE_PID     = 'font-family:var(--font-mono); font-size:0.72rem; color:var(--text-secondary);';
const STYLE_COMMAND = 'font-family:var(--font-mono); font-size:0.75rem; color:var(--color-cyan); max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;';
const STYLE_RIGHT   = 'text-align:right;';
const STYLE_EMPTY   = 'text-align:center; padding:24px; color:var(--text-muted);';

/**
 * The execution-history table, declared once: one entry per column, in the order
 * drawn.
 *
 * Every field here arrives over the wire — a pid, a user, the command that ran,
 * its output — and each one reaches the table as a `text` column (or a function
 * of the row returning text) or as a `node` this page built. Nothing off the wire
 * is written into an attribute except the command's own `title`, set through the
 * DOM, which cannot be broken out of — unlike the JSON-encoded `onclick` the row
 * used to build out of the command's output.
 */
const CRON_LOG_COLUMNS = [
  { text: 'timestamp', style: STYLE_STAMP },
  { text: 'pid', style: STYLE_PID },
  { node: (e) => MicronTable.badge(e.user, 'badge badge-purple', 'font-size:0.65rem;') },
  { text: 'command', style: STYLE_COMMAND, attrs: (e) => ({ title: e.command }) },
  { text: (e) => e.duration || '0.1s', style: STYLE_PID },
  { node: (e) => MicronTable.badge(e.status, 'badge ' + (e.status === 'SUCCESS' ? 'badge-success' : 'badge-error'), 'font-size:0.65rem;') },
  { node: (e) => inspectOutputButton(e), style: STYLE_RIGHT },
];

/**
 * Draws the execution history: one row per execution, or the one row that says
 * there are none. The whole body is swapped at once, so a re-render cannot leave
 * rows behind.
 */
function renderCronLogs(entries) {
  MicronTable.render(document.getElementById('cronLogTableBody'), entries, {
    columns: CRON_LOG_COLUMNS,
    empty: {
      colspan: 7,
      text: 'No execution history recorded yet.',
      style: STYLE_EMPTY,
    },
  });
}

/** The Inspect Output button for one execution, closed over its own row. */
function inspectOutputButton(e) {
  return MicronTable.button('Inspect Output', {
    className: 'btn btn-ghost btn-sm',
    style: 'padding:2px 8px; font-size:0.7rem;',
    // The handler closes over this execution, so its pid and output travel as
    // arguments rather than as markup.
    onClick: () => viewCronOutput('Task PID ' + e.pid, e.output),
  });
}

function openAddCronModal() {
  document.getElementById('cronModalTitle').innerHTML = '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Add Scheduled Task';
  document.getElementById('cronForm').action = '<?= base_url('cron/store') ?>';
  document.getElementById('cron_desc').value = '';
  document.getElementById('cron_cmd').value = '';
  document.getElementById('cron_min').value = '0';
  document.getElementById('cron_hr').value = '2';
  document.getElementById('cron_dom').value = '*';
  document.getElementById('cron_mon').value = '*';
  document.getElementById('cron_dow').value = '*';
  document.getElementById('cron_user').value = 'root';
  document.getElementById('cron_enabled').checked = true;
  document.getElementById('cronSubmitBtn').innerHTML = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg> Save Scheduled Task';
  document.getElementById('cronModal').style.display = 'flex';
}

function openEditCronModal(job) {
  document.getElementById('cronModalTitle').innerHTML = '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg> Edit Scheduled Task';
  document.getElementById('cronForm').action = `<?= base_url('cron') ?>/${job.id}/update`;
  document.getElementById('cron_desc').value = job.description || '';
  document.getElementById('cron_cmd').value = job.command || '';
  document.getElementById('cron_min').value = job.minute || '*';
  document.getElementById('cron_hr').value = job.hour || '*';
  document.getElementById('cron_dom').value = job.day_of_month || '*';
  document.getElementById('cron_mon').value = job.month || '*';
  document.getElementById('cron_dow').value = job.day_of_week || '*';
  document.getElementById('cron_user').value = job.user || 'root';
  document.getElementById('cron_enabled').checked = !!parseInt(job.enabled);
  document.getElementById('cronSubmitBtn').innerHTML = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg> Update Scheduled Task';
  document.getElementById('cronModal').style.display = 'flex';
}

function closeCronModal() {
  document.getElementById('cronModal').style.display = 'none';
}

/**
 * The tasks the server rendered, keyed by id, read from the island it wrote.
 *
 * The rows' buttons carry an id and an action and nothing else, so a task's own
 * values — its description, its command, a run's output — are read here, out of
 * the JSON the page shipped, and never out of an attribute a parser could have
 * been made to read as markup. An island the page could not write, or one that
 * is not a list, simply keys nothing: the table stays inert rather than throwing.
 */
function cronJobsById() {
  const island = document.getElementById('cronJobsData');
  const byId = {};
  let jobs = [];

  if (island) {
    try {
      jobs = JSON.parse(island.textContent || '') || [];
    } catch (err) {
      jobs = [];
    }
  }

  (Array.isArray(jobs) ? jobs : []).forEach(job => {
    if (job && job.id !== undefined && job.id !== null) byId[String(job.id)] = job;
  });

  return byId;
}

/**
 * Binds the two row actions to the buttons the server rendered.
 *
 * Each handler closes over the button it belongs to and looks its job up by id
 * when it is clicked, so the only thing that reaches the modal is what the page
 * itself shipped — a description, a command, a run's output — as data.
 */
function bindCronTaskActions() {
  const jobs = cronJobsById();

  document.querySelectorAll('[data-cron-action]').forEach(button => {
    const action = button.getAttribute('data-cron-action');
    const id = button.getAttribute('data-cron-id');

    button.addEventListener('click', () => {
      const job = jobs[id];
      if (!job) return;

      if (action === 'edit') openEditCronModal(job);
      else viewCronOutput(job.description, job.last_output);
    });
  });
}

function applyTaskPreset(val) {
  const presets = {
    backup: { desc: 'Nightly Configuration Snapshot (disabled until scheduled snapshots, retention and offsite export are implemented)', cmd: 'php spark backup:create', min: '0', hr: '2', dom: '*', mon: '*', dow: '*' },
    dnsbl: { desc: 'Update DNSBL Threat Feeds & Sinkhole', cmd: '/usr/local/bin/dnsbl-update --reload-unbound', min: '30', hr: '3', dom: '*', mon: '*', dow: '*' },
    logrotate: { desc: 'Midnight Log Rotation', cmd: '/usr/sbin/logrotate /etc/logrotate.conf', min: '0', hr: '0', dom: '*', mon: '*', dow: '*' },
    cert: { desc: 'Check SSL/TLS Certificate Expirations', cmd: '/usr/local/bin/micron-cert-renew --check-expiry 30', min: '15', hr: '4', dom: '1', mon: '*', dow: '*' },
    dyndns: { desc: 'Quarter-Hourly Dynamic DNS Verification', cmd: '/usr/local/bin/dyndns-update --iface eth0', min: '*/15', hr: '*', dom: '*', mon: '*', dow: '*' },
    arp: { desc: 'Periodic Stale ARP Table Flush', cmd: '/usr/sbin/ip neigh flush all', min: '0', hr: '*/6', dom: '*', mon: '*', dow: '*' },
    resolve: { desc: 'Refresh Address Object Feeds (FQDN / Internet Service / Geography)', cmd: 'php spark micron:resolve-objects', min: '45', hr: '3', dom: '*', mon: '*', dow: '*' },
    servicesync: { desc: 'Sync Internet Service Database (public prefix feeds + geography)', cmd: 'php spark micron:sync-services --geo', min: '15', hr: '3', dom: '*', mon: '*', dow: '*' },
    ppporedial: { desc: 'PPPoE Link Reconnect & Maintenance (redial down WAN links)', cmd: 'php spark micron:pppoe-redial', min: '*/20', hr: '*', dom: '*', mon: '*', dow: '*' },
  };

  if (presets[val]) {
    const p = presets[val];
    document.getElementById('cron_desc').value = p.desc;
    document.getElementById('cron_cmd').value = p.cmd;
    document.getElementById('cron_min').value = p.min;
    document.getElementById('cron_hr').value = p.hr;
    document.getElementById('cron_dom').value = p.dom;
    document.getElementById('cron_mon').value = p.mon;
    document.getElementById('cron_dow').value = p.dow;
  }
}

function applySchedulePreset(val) {
  const scheds = {
    every_5m: { min: '*/5', hr: '*', dom: '*', mon: '*', dow: '*' },
    every_15m: { min: '*/15', hr: '*', dom: '*', mon: '*', dow: '*' },
    every_hour: { min: '0', hr: '*', dom: '*', mon: '*', dow: '*' },
    daily_midnight: { min: '0', hr: '0', dom: '*', mon: '*', dow: '*' },
    daily_2am: { min: '0', hr: '2', dom: '*', mon: '*', dow: '*' },
    weekly_sunday: { min: '0', hr: '0', dom: '*', mon: '*', dow: '0' },
    monthly_1st: { min: '0', hr: '0', dom: '1', mon: '*', dow: '*' },
  };

  if (scheds[val]) {
    const s = scheds[val];
    document.getElementById('cron_min').value = s.min;
    document.getElementById('cron_hr').value = s.hr;
    document.getElementById('cron_dom').value = s.dom;
    document.getElementById('cron_mon').value = s.mon;
    document.getElementById('cron_dow').value = s.dow;
  }
}

function viewCronOutput(title, output) {
  // The title names the task off the wire, so it is assembled as nodes: the
  // icon, then the text, rather than markup with the title pasted into it.
  document.getElementById('outputModalTitle').replaceChildren(
    fileIcon(),
    document.createTextNode(title + ' Output')
  );
  document.getElementById('outputModalBody').innerText = output || 'No output recorded.';
  document.getElementById('outputModal').style.display = 'flex';
}

/** The output modal's file icon, node-built like the rest of the page. */
function fileIcon() {
  const svg = document.createElementNS(SVG_NS, 'svg');
  for (const [name, value] of Object.entries({
    width: '18', height: '18', fill: 'none', viewBox: '0 0 24 24', stroke: 'currentColor',
    'stroke-width': '2', style: 'color:var(--color-cyan);',
  })) {
    svg.setAttribute(name, value);
  }

  const path = document.createElementNS(SVG_NS, 'path');
  path.setAttribute('stroke-linecap', 'round');
  path.setAttribute('stroke-linejoin', 'round');
  path.setAttribute('d', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z');
  svg.appendChild(path);

  return svg;
}

function closeOutputModal() {
  document.getElementById('outputModal').style.display = 'none';
}

// The rows are server-rendered, so their buttons exist by the time the document
// has been parsed — which is when they are bound to the jobs the island holds.
document.addEventListener('DOMContentLoaded', bindCronTaskActions);

</script>

<?= $this->endSection() ?>
