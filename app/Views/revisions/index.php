<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="container-fluid p-4">

  <!-- Header Banner -->
  <div class="card mb-4" style="background: linear-gradient(135deg, rgba(30, 41, 59, 0.7) 0%, rgba(15, 23, 42, 0.85) 100%); border: 1px solid var(--color-border); border-radius: var(--radius-lg, 12px); box-shadow: 0 8px 24px rgba(0,0,0,0.15);">
    <div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge bg-primary" style="background: linear-gradient(135deg, #6366f1, #8b5cf6) !important; font-size:0.75rem; letter-spacing:0.05em; text-transform:uppercase;">Git Revision Engine</span>
            <span class="badge" style="background:var(--color-surface-2); border:1px solid var(--color-border); color:var(--text-secondary); font-family:var(--font-mono);">v<?= esc($version) ?></span>
            <span class="badge <?= ($channel === 'Development') ? 'badge-warning' : 'badge-success' ?>" style="font-size:0.72rem; letter-spacing:0.04em; text-transform:uppercase; font-weight:700;">
              <?= esc($channel) ?>
            </span>
          </div>
          <h1 class="h3 fw-bold mb-1" style="color:var(--text-primary); letter-spacing:-0.02em;">
            Configuration Revisions &amp; Diff
          </h1>
          <p class="text-muted mb-0" style="font-size:0.9rem;">
            Git-backed audit trail for all appliance configuration changes in <?= esc($projectName) ?>. Inspect unified line-by-line diffs and perform zero-downtime rollbacks.
          </p>
        </div>

        <div class="d-flex gap-2 align-items-center">
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="location.reload()" title="Refresh timeline">
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="me-1">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Refresh
          </button>
          <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#manualCheckpointModal" style="background: linear-gradient(135deg, #3b82f6, #6366f1); border:none; box-shadow:0 4px 12px rgba(99,102,241,0.3);">
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="me-1">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            Create Checkpoint
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Revisions List -->
  <div class="card" style="border: 1px solid var(--color-border); border-radius: var(--radius-lg, 12px); overflow:hidden;">
    <div class="card-header d-flex justify-content-between align-items-center px-4 py-3" style="background: var(--color-surface-2); border-bottom: 1px solid var(--color-border);">
      <div class="d-flex align-items-center gap-2">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-purple, #a855f7);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <span class="fw-semibold" style="color:var(--text-primary); font-size:0.95rem;">Audit History Timeline</span>
      </div>
      <span class="badge" style="background:var(--color-surface-3); color:var(--text-muted); font-size:0.75rem;">
        <?= count($history) ?> Recorded <?= count($history) === 1 ? 'Revision' : 'Revisions' ?>
      </span>
    </div>

    <div class="card-body p-0">
      <?php if (empty($history)): ?>
        <div class="text-center py-5">
          <div class="mb-3" style="color:var(--text-muted);">
            <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
          </div>
          <h5 style="color:var(--text-primary);">No Configuration Commits Yet</h5>
          <p class="text-muted small mb-3">Create your initial configuration checkpoint to begin tracking Git revisions.</p>
          <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#manualCheckpointModal">
            Create Initial Checkpoint
          </button>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" style="color:var(--text-secondary); font-size:0.875rem;">
            <thead style="background:var(--color-surface-1); color:var(--text-muted); font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em;">
              <tr>
                <th class="ps-4" style="width:140px;">Commit</th>
                <th>Description</th>
                <th style="width:130px;">Subsystem</th>
                <th style="width:150px;">Author</th>
                <th style="width:130px;">Diff Stats</th>
                <th style="width:180px;">Recorded At</th>
                <th class="text-end pe-4" style="width:200px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($history as $idx => $rev): ?>
                <tr style="border-bottom: 1px solid var(--color-border);">
                  <!-- Commit Hash -->
                  <td class="ps-4">
                    <span class="font-monospace fw-semibold" style="color:var(--color-cyan, #06b6d4); font-size:0.82rem; cursor:pointer;" onclick="navigator.clipboard.writeText('<?= esc($rev['commit_hash']) ?>'); alert('Copied commit hash: <?= esc($rev['commit_hash']) ?>');" title="Click to copy full SHA-1">
                      <?= esc($rev['short_hash']) ?>
                    </span>
                  </td>

                  <!-- Message -->
                  <td>
                    <div class="fw-medium" style="color:var(--text-primary);">
                      <?= esc($rev['message']) ?>
                    </div>
                  </td>

                  <!-- Subsystem -->
                  <td>
                    <?php
                      $subColor = match(strtolower($rev['subsystem'])) {
                        'interfaces' => 'color:var(--color-cyan, #06b6d4); background:rgba(6,182,212,0.1); border-color:rgba(6,182,212,0.3);',
                        'firewall'   => 'color:var(--color-amber, #f59e0b); background:rgba(245,158,11,0.1); border-color:rgba(245,158,11,0.3);',
                        'rollback'   => 'color:var(--color-rose, #f43f5e); background:rgba(244,63,94,0.1); border-color:rgba(244,63,94,0.3);',
                        'manual'     => 'color:var(--color-purple, #a855f7); background:rgba(168,85,247,0.1); border-color:rgba(168,85,247,0.3);',
                        default      => 'color:var(--text-muted); background:var(--color-surface-3); border-color:var(--color-border);',
                      };
                    ?>
                    <span class="badge border" style="<?= $subColor ?> font-size:0.72rem; text-transform:uppercase;">
                      <?= esc($rev['subsystem']) ?>
                    </span>
                  </td>

                  <!-- Author -->
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <div style="width:22px; height:22px; border-radius:50%; background:linear-gradient(135deg, #3b82f6, #8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-size:0.65rem; font-weight:700;">
                        <?= strtoupper(substr((string)$rev['author'], 0, 1)) ?>
                      </div>
                      <span style="font-size:0.82rem;"><?= esc($rev['author']) ?></span>
                    </div>
                  </td>

                  <!-- Diff Stats -->
                  <td>
                    <?php if (!empty($rev['diff_stats'])): ?>
                      <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25" style="font-family:var(--font-mono); font-size:0.75rem;">
                        +<?= (int)($rev['diff_stats']['additions'] ?? 0) ?>
                      </span>
                      <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25" style="font-family:var(--font-mono); font-size:0.75rem;">
                        -<?= (int)($rev['diff_stats']['deletions'] ?? 0) ?>
                      </span>
                    <?php else: ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </td>

                  <!-- Created At -->
                  <td>
                    <div style="font-size:0.82rem;" title="<?= esc($rev['created_at']) ?>">
                      <?= esc($rev['created_at']) ?>
                    </div>
                  </td>

                  <!-- Actions -->
                  <td class="text-end pe-4">
                    <div class="btn-group btn-group-sm">
                      <button type="button" class="btn btn-outline-info" onclick="openDiffModal('<?= esc($rev['commit_hash']) ?>', '<?= esc(addslashes($rev['message'])) ?>')" title="View visual diff">
                        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="me-1">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                          <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        Diff
                      </button>
                      <a href="<?= base_url('system/revisions/export/' . esc($rev['commit_hash'])) ?>" class="btn btn-outline-secondary" title="Export JSON snapshot">
                        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                      </a>
                      <button type="button" class="btn btn-outline-danger" onclick="openRollbackModal('<?= esc($rev['commit_hash']) ?>', '<?= esc(addslashes($rev['message'])) ?>')" title="Restore configuration to this checkpoint">
                        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                        </svg>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- Modal: Manual Checkpoint -->
<div class="modal fade" id="manualCheckpointModal" tabindex="-1" aria-labelledby="manualCheckpointTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg, 12px);">
      <div class="modal-header" style="border-bottom:1px solid var(--color-border);">
        <h5 class="modal-title" id="manualCheckpointTitle" style="color:var(--text-primary); font-size:1.1rem; font-weight:700;">
          Create Configuration Checkpoint
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="<?= base_url('system/revisions/commit') ?>" method="POST">
        <?= csrf_field() ?>
        <div class="modal-body">
          <p class="text-muted small mb-3">
            Serializes active system state, increments project patch version (current: <code>v<?= esc($version) ?></code>), and commits an immutable snapshot to the appliance Git repository.
          </p>
          <div class="mb-3">
            <label for="checkpointMessage" class="form-label fw-medium" style="color:var(--text-secondary); font-size:0.85rem;">
              Checkpoint Description / Message
            </label>
            <input type="text" class="form-control" id="checkpointMessage" name="message" placeholder="e.g. Pre-maintenance interface reconfiguration" required autofocus style="background:var(--color-surface-2); border-color:var(--color-border); color:var(--text-primary);">
          </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--color-border);">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm" style="background:linear-gradient(135deg, #3b82f6, #6366f1); border:none;">
            Commit Checkpoint
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Visual Diff Viewer -->
<div class="modal fade" id="diffViewerModal" tabindex="-1" aria-labelledby="diffViewerTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg, 12px); box-shadow:0 12px 36px rgba(0,0,0,0.3);">
      <div class="modal-header d-flex justify-content-between align-items-center" style="border-bottom:1px solid var(--color-border);">
        <div>
          <h5 class="modal-title" id="diffViewerTitle" style="color:var(--text-primary); font-size:1.05rem; font-weight:700;">
            Unified Diff Inspector
          </h5>
          <div id="diffCommitMeta" class="text-muted small mt-1 font-monospace"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0" id="diffViewerBody" style="min-height:300px; max-height:70vh; overflow-y:auto; background:var(--color-surface-base, #0b0f19);">
        <!-- Populated via AJAX -->
        <div class="text-center py-5 text-muted" id="diffViewerLoading">
          <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
          Loading Git unified diff...
        </div>
        <div id="diffViewerContent" class="d-none font-monospace" style="font-size:0.8rem; line-height:1.5;"></div>
      </div>
      <div class="modal-footer d-flex justify-content-between" style="border-top:1px solid var(--color-border);">
        <div id="diffStatsSummary" class="text-muted small font-monospace"></div>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Rollback Confirmation -->
<div class="modal fade" id="rollbackConfirmModal" tabindex="-1" aria-labelledby="rollbackModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg, 12px);">
      <div class="modal-header" style="border-bottom:1px solid var(--color-border);">
        <div class="d-flex align-items-center gap-2">
          <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="text-danger">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
          <h5 class="modal-title" id="rollbackModalTitle" style="color:var(--text-primary); font-size:1.1rem; font-weight:700;">
            Confirm Configuration Rollback
          </h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="rollbackForm" action="" method="POST">
        <?= csrf_field() ?>
        <div class="modal-body">
          <p style="color:var(--text-secondary); font-size:0.9rem;">
            Are you sure you want to restore all appliance configuration to the selected revision?
          </p>
          <div class="p-3 mb-3" style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.25); border-radius:8px;">
            <div class="fw-semibold text-danger small mb-1">Target Revision:</div>
            <div id="rollbackTargetHash" class="font-monospace text-light small mb-1"></div>
            <div id="rollbackTargetMsg" class="text-muted small"></div>
          </div>
          <p class="text-muted small mb-0">
            <strong>Warning:</strong> Current database configuration tables will be atomically restored from the snapshot. A rollback commit will be recorded in the Git history.
          </p>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--color-border);">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger btn-sm">
            Execute Rollback Now
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openDiffModal(commitHash, message) {
  const modalEl = document.getElementById('diffViewerModal');
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  
  document.getElementById('diffCommitMeta').textContent = `Commit: ${commitHash.substring(0, 10)} — ${message}`;
  document.getElementById('diffViewerLoading').classList.remove('d-none');
  document.getElementById('diffViewerContent').classList.add('d-none');
  document.getElementById('diffViewerContent').innerHTML = '';
  document.getElementById('diffStatsSummary').textContent = '';
  
  modal.show();

  MicronScheduler.fetch(`<?= base_url('system/revisions/diff') ?>/${commitHash}?format=json`, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(res => res.json())
  .then(data => {
    document.getElementById('diffViewerLoading').classList.add('d-none');
    const container = document.getElementById('diffViewerContent');
    container.classList.remove('d-none');

    if (!data.success || !data.diff || !data.diff.lines || data.diff.lines.length === 0) {
      container.innerHTML = `<div class="p-4 text-center text-muted">No changes or initial commit.</div>`;
      return;
    }

    document.getElementById('diffStatsSummary').innerHTML = 
      `<span class="text-success">+${data.diff.additions} additions</span>, ` +
      `<span class="text-danger">-${data.diff.deletions} deletions</span>`;

    let html = '<div class="diff-table" style="width:100%; border-collapse:collapse;">';
    data.diff.lines.forEach(line => {
      let bg = 'transparent';
      let fg = 'var(--text-secondary)';
      let sign = ' ';

      if (line.type === 'add') {
        bg = 'rgba(16, 185, 129, 0.12)';
        fg = '#34d399';
        sign = '+';
      } else if (line.type === 'delete') {
        bg = 'rgba(239, 68, 68, 0.12)';
        fg = '#f87171';
        sign = '-';
      } else if (line.type === 'header') {
        bg = 'rgba(99, 102, 241, 0.15)';
        fg = '#a5b4fc';
        sign = '@';
      }

      const oldNum = line.old_num !== null ? line.old_num : '';
      const newNum = line.new_num !== null ? line.new_num : '';
      const escContent = escapeHtml(line.content);

      html += `<div style="display:flex; background:${bg}; color:${fg}; padding:2px 8px; border-bottom:1px solid rgba(255,255,255,0.02);">` +
        `<span style="width:45px; text-align:right; color:var(--text-muted); opacity:0.6; user-select:none; margin-right:8px;">${oldNum}</span>` +
        `<span style="width:45px; text-align:right; color:var(--text-muted); opacity:0.6; user-select:none; margin-right:12px;">${newNum}</span>` +
        `<span style="width:16px; user-select:none; font-weight:700;">${sign}</span>` +
        `<span style="flex:1; white-space:pre-wrap; word-break:break-all;">${escContent}</span>` +
      `</div>`;
    });
    html += '</div>';
    container.innerHTML = html;
  })
  .catch(err => {
    document.getElementById('diffViewerLoading').classList.add('d-none');
    document.getElementById('diffViewerContent').classList.remove('d-none');
    document.getElementById('diffViewerContent').innerHTML = `<div class="p-4 text-center text-danger">Failed to load diff: ${err.message}</div>`;
  });
}

function openRollbackModal(commitHash, message) {
  const modalEl = document.getElementById('rollbackConfirmModal');
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  
  document.getElementById('rollbackTargetHash').textContent = commitHash;
  document.getElementById('rollbackTargetMsg').textContent = message;
  document.getElementById('rollbackForm').action = `<?= base_url('system/revisions/rollback') ?>/${commitHash}`;
  
  modal.show();
}

function escapeHtml(text) {
  const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
  return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}
</script>
<?= $this->endSection() ?>
