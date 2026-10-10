<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?= $this->include('firewall/_obj_tooltip') ?>
<?php
$svcMap = [];
foreach (($services ?? []) as $__s) {
    $svcMap[$__s['name']] = $__s;
}
?>

<style>
/* ── Chips ── */
.mno-obj-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 0.74rem;
  font-weight: 500;
  font-family: var(--font-mono);
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.12);
  color: var(--text-primary);
  white-space: nowrap;
  line-height: 1.4;
}
.mno-chip-grp { background: rgba(139,92,246,0.15); border-color: rgba(139,92,246,0.35); color: #c4b5fd; }
.mno-chip-svc { background: rgba(34,197,94,0.12);  border-color: rgba(34,197,94,0.30);  color: #86efac; }
.mno-chip-icon {
  width: 12px; height: 12px;
  flex-shrink: 0;
  display: inline-block;
  vertical-align: middle;
}

/* ── Modal ── */
.mno-modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.75);
  backdrop-filter: blur(4px);
  z-index: 1000;
  display: none;
  align-items: center; justify-content: center;
  padding: 20px;
}
.mno-modal-backdrop.active { display: flex; }
.mno-modal-dialog {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border-bright);
  border-radius: var(--radius-lg);
  width: 100%; max-width: 660px; max-height: 90vh;
  display: flex; flex-direction: column;
  box-shadow: 0 25px 50px -12px rgba(0,0,0,0.7);
  overflow: hidden;
  animation: modalSlideIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalSlideIn {
  from { opacity: 0; transform: translateY(-18px) scale(0.97); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}
.mno-modal-header {
  padding: 16px 24px;
  border-bottom: 1px solid var(--color-border);
  display: flex; justify-content: space-between; align-items: center;
  background: var(--color-surface-2);
}
.mno-modal-body   { padding: 24px; overflow-y: auto; flex: 1; }
.mno-modal-footer {
  padding: 14px 24px;
  border-top: 1px solid var(--color-border);
  background: var(--color-surface-2);
  display: flex; justify-content: flex-end; gap: 10px;
}

/* ── Table ── */
.mno-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 0.8125rem;
}
.mno-table th {
  background: var(--color-surface-3);
  color: var(--text-secondary);
  font-weight: 600; font-size: 0.72rem;
  text-transform: uppercase; letter-spacing: 0.05em;
  padding: 10px 14px;
  border-bottom: 1px solid var(--color-border);
  text-align: left;
}
.mno-table td {
  padding: 10px 14px;
  border-bottom: 1px solid var(--color-border);
  vertical-align: middle;
}
.mno-table tbody tr:hover { background: rgba(255,255,255,0.02); }
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="display:flex; align-items:center; gap:10px;">
        <h1 class="page-title">Firewall Service Groups</h1>
        <span class="badge badge-info" style="font-size:0.75rem;"><?= count($serviceGroups) ?> Groups</span>
      </div>
      <p class="page-subtitle">Group multiple service objects into named bundles for simplified firewall policy rules</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
      <button type="button" class="btn btn-primary btn-sm" onclick="openCreateModal()">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Create Service Group
      </button>

      <a href="<?= base_url('firewall/service-groups/export-csv') ?>" class="btn btn-ghost btn-sm" title="Export Groups to CSV">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Export CSV
      </a>

      <button type="button" class="btn btn-ghost btn-sm" onclick="openImportModal()" title="Import Groups from CSV">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
        </svg>
        Import CSV
      </button>

      <a href="<?= base_url('firewall/services') ?>" class="btn btn-ghost btn-sm">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/>
        </svg>
        Services
      </a>
    </div>
  </div>
</div>

<!-- ── Flash Messages ── -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:16px;">
    <?= esc(session()->getFlashdata('success')) ?>
  </div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:16px;">
    <?= esc(session()->getFlashdata('error')) ?>
  </div>
<?php endif; ?>

<!-- ── Toolbar / Search ── -->
<div class="card" style="padding:12px 18px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
  <div style="position:relative;">
    <input type="text" id="grpSearchInput" placeholder="Search service groups..."
           oninput="filterTable()"
           style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 12px 6px 32px; border-radius:var(--radius-sm); font-size:0.8rem; width:270px;">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
         style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-muted);">
      <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
    </svg>
  </div>
  <div style="font-size:0.75rem; color:var(--text-muted);">
    Total: <strong style="color:var(--text-primary);" id="filteredCount"><?= count($serviceGroups) ?></strong> service groups
  </div>
</div>

<!-- ── Service Groups Table ── -->
<div class="card card-glass animate-in animate-in-delay-1" style="padding:0; overflow-x:auto;">
  <table class="mno-table" id="groupsTable">
    <thead>
      <tr>
        <th style="min-width:200px;">Group Name</th>
        <th style="min-width:340px;">Member Services</th>
        <th style="width:80px; text-align:center;">Count</th>
        <th>Comment</th>
        <th style="width:90px; text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($serviceGroups as $sg): ?>
        <?php
          $members = is_string($sg['members']) ? json_decode($sg['members'], true) : $sg['members'];
          $members = is_array($members) ? $members : [];
          $searchData = strtolower($sg['name'] . ' ' . implode(' ', $members) . ' ' . ($sg['comment'] ?? ''));
        ?>
        <tr data-search="<?= esc($searchData) ?>">
          <td>
            <span class="mno-obj-chip mno-chip-grp"<?= \App\Libraries\ObjectTip::serviceGroup($sg, $svcMap) ?>>
              <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
              </svg>
              <span><?= esc($sg['name']) ?></span>
            </span>
          </td>
          <td>
            <div style="display:flex; flex-wrap:wrap; gap:4px; max-width:560px;">
              <?php if (!empty($members)): ?>
                <?php foreach ($members as $m): ?>
                  <span class="mno-obj-chip mno-chip-svc"<?= isset($svcMap[$m]) ? \App\Libraries\ObjectTip::service($svcMap[$m]) : '' ?>>
                    <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                    <span><?= esc($m) ?></span>
                  </span>
                <?php endforeach; ?>
              <?php else: ?>
                <span style="color:var(--text-muted); font-size:0.75rem;">(Empty group)</span>
              <?php endif; ?>
            </div>
          </td>
          <td style="text-align:center;">
            <span class="badge badge-muted" style="font-size:0.7rem; font-family:var(--font-mono);">
              <?= count($members) ?>
            </span>
          </td>
          <td style="color:var(--text-secondary); font-size:0.75rem;">
            <?= esc($sg['comment'] ?: '—') ?>
          </td>
          <td style="text-align:right; white-space:nowrap;">
            <button type="button" class="btn btn-ghost btn-sm"
                    onclick='editGroup(<?= htmlspecialchars(json_encode($sg), ENT_QUOTES, 'UTF-8') ?>)'
                    title="Edit Group" style="padding:4px 8px; font-size:0.75rem;">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/>
              </svg>
            </button>

            <form method="POST" action="<?= base_url('firewall/service-groups/' . esc($sg['id']) . '/delete') ?>"
                  style="display:inline;"
                  onsubmit="return confirm('Delete service group <?= esc($sg['name']) ?>?');">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-ghost btn-sm" title="Delete Group"
                      style="color:var(--color-red); padding:4px 8px; font-size:0.75rem;">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ========================================================
     MODAL: Create / Edit Service Group
     ======================================================== -->
<div class="mno-modal-backdrop" id="groupModal">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
        </svg>
        <span style="font-weight:700; font-size:1.05rem; color:var(--text-primary);" id="groupModalTitle">New Service Group</span>
      </div>
      <button type="button" onclick="closeGroupModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form method="POST" id="groupForm" action="<?= base_url('firewall/service-groups/store') ?>">
      <?= csrf_field() ?>
      <div class="mno-modal-body">

        <!-- Name + Comment -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:18px;">
          <div>
            <label style="display:block; font-size:0.73rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Group Name <span style="color:var(--color-red);">*</span>
            </label>
            <input type="text" name="name" id="grpName" required placeholder="e.g. Web_Services"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
          </div>
          <div>
            <label style="display:block; font-size:0.73rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Comment / Notes
            </label>
            <input type="text" name="comment" id="grpComment" placeholder="Optional description..."
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
          </div>
        </div>

        <!-- Member Services picker -->
        <div>
          <label style="display:flex; justify-content:space-between; align-items:center; font-size:0.73rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:8px;">
            <span>Member Services <span style="color:var(--color-red);">*</span></span>
            <span style="font-size:0.7rem; font-weight:400; color:var(--text-muted);">Check all services to include</span>
          </label>

          <!-- Search within picker -->
          <div style="position:relative; margin-bottom:8px;">
            <input type="text" id="memberSearchInput" placeholder="Filter services..."
                   oninput="filterMemberList()"
                   style="width:100%; background:var(--color-surface-2); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px 6px 30px; border-radius:var(--radius-sm); font-size:0.8rem;">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                 style="position:absolute; left:9px; top:50%; transform:translateY(-50%); color:var(--text-muted);">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
          </div>

          <div id="memberList" style="max-height:230px; overflow-y:auto; background:var(--color-surface-3); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:10px; display:grid; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr)); gap:6px;">
            <?php foreach ($services as $svc): ?>
              <label class="member-item" data-label="<?= strtolower(esc($svc['name'])) ?>"
                     style="display:flex; align-items:center; gap:7px; font-size:0.8rem; cursor:pointer; padding:4px 6px; border-radius:4px; transition:background .15s;">
                <input type="checkbox" name="members[]" value="<?= esc($svc['name']) ?>" class="grp-member-check">
                <span style="font-family:var(--font-mono); color:var(--text-primary);"><?= esc($svc['name']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div style="display:flex; justify-content:space-between; margin-top:6px; font-size:0.72rem; color:var(--text-muted);">
            <span id="selectedCount">0 selected</span>
            <button type="button" onclick="clearAllMembers()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:0.72rem; padding:0;">Clear all</button>
          </div>
        </div>

      </div><!-- /.mno-modal-body -->

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeGroupModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="grpSubmitBtn">Save Service Group</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================
     MODAL: CSV Import
     ======================================================== -->
<div class="mno-modal-backdrop" id="csvImportModal">
  <div class="mno-modal-dialog" style="max-width:520px;">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
        </svg>
        <span style="font-weight:700; font-size:1.05rem; color:var(--text-primary);">Import Service Groups (CSV)</span>
      </div>
      <button type="button" onclick="closeImportModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form method="POST" action="<?= base_url('firewall/service-groups/import-csv') ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="mno-modal-body" style="padding:20px;">
        <p style="font-size:0.85rem; color:var(--text-secondary); margin-bottom:16px;">
          Upload a CSV file containing service groups. Supported columns:
          <code>name, members, comment</code> (members can be comma-separated service names).
        </p>

        <div style="margin-bottom:16px;">
          <label style="display:block; font-size:0.73rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Choose CSV File <span style="color:var(--color-red);">*</span>
          </label>
          <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" required
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px; border-radius:var(--radius-sm); font-size:0.85rem;">
        </div>

        <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px; margin-bottom:16px;">
          <label style="display:block; font-size:0.73rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Conflict Resolution
          </label>
          <div style="display:flex; gap:16px;">
            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.8125rem;">
              <input type="radio" name="mode" value="update" checked>
              <span>Update existing (by name)</span>
            </label>
            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.8125rem;">
              <input type="radio" name="mode" value="skip">
              <span>Skip existing</span>
            </label>
          </div>
        </div>

        <div style="font-size:0.75rem;">
          <a href="<?= base_url('firewall/service-groups/export-csv') ?>" style="color:var(--color-cyan); text-decoration:none;">
            Download sample CSV template &darr;
          </a>
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeImportModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Upload &amp; Import</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Table search filter ──
function filterTable() {
  var query = document.getElementById('grpSearchInput').value.toLowerCase();
  var rows  = document.querySelectorAll('#groupsTable tbody tr');
  var visible = 0;
  rows.forEach(function(row) {
    var text = row.getAttribute('data-search') || '';
    if (text.includes(query)) { row.style.display = ''; visible++; }
    else                      { row.style.display = 'none'; }
  });
  document.getElementById('filteredCount').innerText = visible;
}

// ── Member list search ──
function filterMemberList() {
  var query = document.getElementById('memberSearchInput').value.toLowerCase();
  document.querySelectorAll('.member-item').forEach(function(el) {
    el.style.display = el.getAttribute('data-label').includes(query) ? '' : 'none';
  });
}

// ── Update selected count label ──
function updateSelectedCount() {
  var n = document.querySelectorAll('.grp-member-check:checked').length;
  document.getElementById('selectedCount').innerText = n + ' selected';
}
document.querySelectorAll('.grp-member-check').forEach(function(cb) {
  cb.addEventListener('change', updateSelectedCount);
});

function clearAllMembers() {
  document.querySelectorAll('.grp-member-check').forEach(function(cb) { cb.checked = false; });
  updateSelectedCount();
}

// ── Open Create Modal ──
function openCreateModal() {
  document.getElementById('groupModalTitle').innerText = 'New Service Group';
  document.getElementById('groupForm').action = '<?= base_url('firewall/service-groups/store') ?>';
  document.getElementById('grpSubmitBtn').innerText = 'Save Service Group';
  document.getElementById('grpName').value    = '';
  document.getElementById('grpComment').value = '';
  document.getElementById('memberSearchInput').value = '';
  document.querySelectorAll('.member-item').forEach(function(el) { el.style.display = ''; });
  document.querySelectorAll('.grp-member-check').forEach(function(cb) { cb.checked = false; });
  updateSelectedCount();
  document.getElementById('groupModal').classList.add('active');
}

// ── Open Edit Modal ──
function editGroup(data) {
  document.getElementById('groupModalTitle').innerText = 'Edit Service Group: ' + data.name;
  document.getElementById('groupForm').action =
    '<?= base_url('firewall/service-groups') ?>/' + encodeURIComponent(data.id) + '/update';
  document.getElementById('grpSubmitBtn').innerText = 'Save Changes';
  document.getElementById('grpName').value    = data.name    || '';
  document.getElementById('grpComment').value = data.comment || '';
  document.getElementById('memberSearchInput').value = '';
  document.querySelectorAll('.member-item').forEach(function(el) { el.style.display = ''; });

  var members = [];
  try { members = JSON.parse(data.members || '[]'); }
  catch(e) { members = Array.isArray(data.members) ? data.members : []; }

  document.querySelectorAll('.grp-member-check').forEach(function(cb) {
    cb.checked = members.includes(cb.value);
  });
  updateSelectedCount();
  document.getElementById('groupModal').classList.add('active');
}

// ── Close Modals ──
function closeGroupModal() { document.getElementById('groupModal').classList.remove('active'); }
function openImportModal()  { document.getElementById('csvImportModal').classList.add('active'); }
function closeImportModal() { document.getElementById('csvImportModal').classList.remove('active'); }

// Close on backdrop click
document.getElementById('groupModal').addEventListener('click', function(e) {
  if (e.target === this) closeGroupModal();
});
document.getElementById('csvImportModal').addEventListener('click', function(e) {
  if (e.target === this) closeImportModal();
});
</script>

<?= $this->endSection() ?>
