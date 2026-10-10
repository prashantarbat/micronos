<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <h1 class="page-title">Create Security Role</h1>
      <p class="page-subtitle">Define custom permissions and delegation policies for users</p>
    </div>
    <a href="<?= base_url('roles') ?>" class="btn btn-ghost">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
      </svg>
      Back to Roles
    </a>
  </div>
</div>

<form method="POST" action="<?= base_url('roles/store') ?>" id="roleForm" class="animate-in animate-in-delay-1">
  <?= csrf_field() ?>

  <!-- ============================================================
       Role Details Card
  ============================================================ -->
  <div class="card card-glass" style="margin-bottom:24px;">
    <div class="card-header">
      <span class="card-title">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        Role Profile
      </span>
    </div>
    <div class="card-body">
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:20px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="display_name">Display Name <span style="color:var(--color-red);">*</span></label>
          <input type="text" id="display_name" name="display_name" class="form-control" required
                 placeholder="e.g. SOC Analyst Level 1" value="<?= esc(old('display_name')) ?>">
          <div class="form-hint">Human-readable name displayed in the UI and user assignments.</div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="name">Role Identifier / Slug <span style="color:var(--color-red);">*</span></label>
          <input type="text" id="name" name="name" class="form-control" required
                 placeholder="e.g. soc_analyst" pattern="[a-zA-Z0-9_\-]+"
                 value="<?= esc(old('name')) ?>" style="font-family:var(--font-mono);">
          <div class="form-hint">Unique alphanumeric slug (letters, numbers, underscores, hyphens).</div>
        </div>
      </div>

      <div class="form-group" style="margin-top:20px; margin-bottom:0;">
        <label class="form-label" for="description">Role Description</label>
        <textarea id="description" name="description" class="form-control" rows="2"
                  placeholder="Summarize the operational scope, responsibilities, and restrictions for this role..."><?= esc(old('description')) ?></textarea>
      </div>
    </div>
  </div>

  <!-- ============================================================
       Permissions Assignment Grid
  ============================================================ -->
  <div class="card card-glass" style="margin-bottom:24px;">
    <div class="card-header" style="flex-wrap:wrap; gap:12px;">
      <div style="display:flex; align-items:center; gap:10px;">
        <span class="card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
          </svg>
          Assigned Permissions
        </span>
        <span id="selectedCountBadge" class="badge badge-info" style="font-family:var(--font-mono);">
          0 Selected
        </span>
      </div>

      <!-- Quick Actions -->
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <input type="text" id="permFilter" class="form-control" placeholder="Filter permissions..." style="padding:6px 12px; font-size:0.8rem; width:200px;">
        <button type="button" class="btn btn-ghost btn-sm" id="btnSelectAll">Select All</button>
        <button type="button" class="btn btn-ghost btn-sm" id="btnDeselectAll">Deselect All</button>
      </div>
    </div>

    <div class="card-body" style="padding:24px;">
      <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap:20px;" id="modulesContainer">
        <?php foreach ($groupedPerms as $module => $perms): ?>
          <div class="card module-card" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-md); overflow:hidden;" data-module="<?= esc(strtolower($module)) ?>">
            
            <!-- Module Card Header -->
            <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 16px; background:rgba(255,255,255,0.02); border-bottom:1px solid var(--color-border);">
              <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:700; font-size:0.85rem; color:var(--text-primary); text-transform:uppercase; letter-spacing:0.06em; margin:0;">
                <input type="checkbox" class="module-master-toggle" data-module="<?= esc($module) ?>" style="accent-color:var(--color-cyan); width:16px; height:16px; cursor:pointer;">
                <?= esc($module) ?>
              </label>
              <span class="badge badge-muted" style="font-size:0.65rem;"><?= count($perms) ?> perms</span>
            </div>

            <!-- Permission Items -->
            <div style="padding:12px 16px; display:flex; flex-direction:column; gap:10px;">
              <?php foreach ($perms as $p): ?>
                <?php $isChecked = in_array($p['id'], $selectedPerms ?? []); ?>
                <label class="perm-item" style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; margin:0; padding:4px 0;"
                       data-perm-key="<?= esc(strtolower($p['key'])) ?>"
                       data-perm-label="<?= esc(strtolower($p['label'])) ?>"
                       data-perm-desc="<?= esc(strtolower($p['description'] ?? '')) ?>">
                  <input type="checkbox" name="permissions[]" value="<?= esc($p['id']) ?>"
                         class="perm-checkbox" data-module="<?= esc($module) ?>"
                         <?= $isChecked ? 'checked' : '' ?>
                         style="accent-color:var(--color-cyan); width:16px; height:16px; margin-top:2px; cursor:pointer;">
                  <div style="flex:1;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                      <span style="font-size:0.82rem; font-weight:600; color:var(--text-primary);">
                        <?= esc($p['label']) ?>
                      </span>
                    </div>
                    <div style="font-family:var(--font-mono); font-size:0.72rem; color:var(--color-cyan); margin-top:1px;">
                      <?= esc($p['key']) ?>
                    </div>
                    <?php if (!empty($p['description'])): ?>
                      <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px; line-height:1.3;">
                        <?= esc($p['description']) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>

          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Actions -->
  <div style="display:flex; justify-content:flex-end; gap:12px; margin-bottom:40px;">
    <a href="<?= base_url('roles') ?>" class="btn btn-ghost">Cancel</a>
    <button type="submit" class="btn btn-primary" style="min-width:140px;">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
      </svg>
      Create Role
    </button>
  </div>
</form>

<script>
function updateCounts() {
  const allPerms = document.querySelectorAll('.perm-checkbox');
  const checked = document.querySelectorAll('.perm-checkbox:checked');
  const badge = document.getElementById('selectedCountBadge');
  if (badge) {
    badge.innerText = `${checked.length} of ${allPerms.length} Selected`;
  }

  // Update module master checkboxes
  document.querySelectorAll('.module-master-toggle').forEach(master => {
    const mod = master.getAttribute('data-module');
    const modBoxes = document.querySelectorAll(`.perm-checkbox[data-module="${mod}"]`);
    const modChecked = document.querySelectorAll(`.perm-checkbox[data-module="${mod}"]:checked`);
    master.checked = modBoxes.length > 0 && modBoxes.length === modChecked.length;
    master.indeterminate = modChecked.length > 0 && modChecked.length < modBoxes.length;
  });
}

// Module Master Toggles
document.querySelectorAll('.module-master-toggle').forEach(master => {
  master.addEventListener('change', function() {
    const mod = this.getAttribute('data-module');
    document.querySelectorAll(`.perm-checkbox[data-module="${mod}"]`).forEach(cb => {
      // only toggle if visible (not hidden by filter)
      const item = cb.closest('.perm-item');
      if (!item || item.style.display !== 'none') {
        cb.checked = master.checked;
      }
    });
    updateCounts();
  });
});

// Permission Checkboxes
document.querySelectorAll('.perm-checkbox').forEach(cb => {
  cb.addEventListener('change', updateCounts);
});

// Select All / Deselect All
document.getElementById('btnSelectAll')?.addEventListener('click', () => {
  document.querySelectorAll('.perm-checkbox').forEach(cb => {
    const item = cb.closest('.perm-item');
    if (!item || item.style.display !== 'none') {
      cb.checked = true;
    }
  });
  updateCounts();
});

document.getElementById('btnDeselectAll')?.addEventListener('click', () => {
  document.querySelectorAll('.perm-checkbox').forEach(cb => {
    const item = cb.closest('.perm-item');
    if (!item || item.style.display !== 'none') {
      cb.checked = false;
    }
  });
  updateCounts();
});

// Live filter
document.getElementById('permFilter')?.addEventListener('input', function(e) {
  const q = e.target.value.toLowerCase().trim();
  document.querySelectorAll('.module-card').forEach(card => {
    let hasVisible = false;
    card.querySelectorAll('.perm-item').forEach(item => {
      const k = item.getAttribute('data-perm-key') || '';
      const l = item.getAttribute('data-perm-label') || '';
      const d = item.getAttribute('data-perm-desc') || '';
      const match = !q || k.includes(q) || l.includes(q) || d.includes(q);
      item.style.display = match ? 'flex' : 'none';
      if (match) hasVisible = true;
    });
    card.style.display = hasVisible ? 'block' : 'none';
  });
});

// Auto slug generation from display name
const displayNameInput = document.getElementById('display_name');
const nameInput = document.getElementById('name');
if (displayNameInput && nameInput) {
  displayNameInput.addEventListener('input', () => {
    if (!nameInput.dataset.manualEdited) {
      nameInput.value = displayNameInput.value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
    }
  });
  nameInput.addEventListener('input', () => {
    nameInput.dataset.manualEdited = 'true';
  });
}

updateCounts();
</script>

<?= $this->endSection() ?>
