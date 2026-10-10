<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="display:flex; align-items:center; gap:10px; margin-bottom:4px;">
        <h1 class="page-title" style="margin:0;">Edit Role: <?= esc($role['display_name']) ?></h1>
        <?php if ((int)($role['is_system'] ?? 0) === 1): ?>
          <span class="badge badge-purple" style="font-size:0.75rem;">System Role</span>
        <?php else: ?>
          <span class="badge badge-info" style="font-size:0.75rem;">Custom Role</span>
        <?php endif; ?>
      </div>
      <p class="page-subtitle">Configure granular privileges and assign or revoke operational capabilities</p>
    </div>
    <a href="<?= base_url('roles') ?>" class="btn btn-ghost">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
      </svg>
      Back to Roles
    </a>
  </div>
</div>

<form method="POST" action="<?= base_url('roles/update/' . esc($role['id'])) ?>" id="roleForm" class="animate-in animate-in-delay-1">
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
                 value="<?= esc(old('display_name', $role['display_name'])) ?>">
          <div class="form-hint">Human-readable name shown in user lists and assignments.</div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="name">
            Role Identifier / Slug
            <?php if ((int)($role['is_system'] ?? 0) === 1): ?>
              <span style="color:var(--color-purple); font-size:0.75rem;">(Locked for System Roles)</span>
            <?php else: ?>
              <span style="color:var(--color-red);">*</span>
            <?php endif; ?>
          </label>
          <input type="text" id="name" name="name" class="form-control"
                 value="<?= esc(old('name', $role['name'])) ?>"
                 <?= ((int)($role['is_system'] ?? 0) === 1) ? 'readonly style="opacity:0.65; cursor:not-allowed; font-family:var(--font-mono);"' : 'required style="font-family:var(--font-mono);"' ?>>
          <div class="form-hint">
            <?= ((int)($role['is_system'] ?? 0) === 1) ? 'Built-in role identifier cannot be altered.' : 'Alphanumeric identifier used in policy definitions.' ?>
          </div>
        </div>
      </div>

      <div class="form-group" style="margin-top:20px; margin-bottom:0;">
        <label class="form-label" for="description">Role Description</label>
        <textarea id="description" name="description" class="form-control" rows="2"
                  placeholder="Summarize the operational scope and restrictions..."><?= esc(old('description', $role['description'])) ?></textarea>
      </div>
    </div>
  </div>

  <!-- ============================================================
       External Group Mappings Card
  ============================================================ -->
  <div class="card card-glass" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="card-title">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
        </svg>
        External Directory & RADIUS Groups Mapped to this Role
      </span>
      <button type="button" class="btn btn-primary btn-sm" onclick="openEditRoleGroupMappingModal()">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
        Map External Group to this Role
      </button>
    </div>
    <div class="card-body">
      <p style="font-size:0.83rem; color:var(--text-secondary); margin-bottom:14px;">
        Users belonging to any of the external Active Directory security groups or returning the RADIUS attributes below will automatically receive this role upon sign-in.
      </p>

      <table class="iface-table">
        <thead>
          <tr>
            <th>Provider Type</th>
            <th>External Group Identifier / RADIUS Attribute</th>
            <th style="width:100px;">Priority</th>
            <th style="text-align:right; width:80px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($mappedGroups)): ?>
            <tr>
              <td colspan="4" style="text-align:center; padding:18px; color:var(--text-muted); font-size:0.85rem;">
                No external groups currently mapped directly to this role.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($mappedGroups as $mg): ?>
              <tr>
                <td>
                  <?php if ($mg['source_type'] === 'ldap'): ?>
                    <span class="badge badge-info" style="font-size:0.7rem; font-weight:600;">Active Directory / LDAP</span>
                  <?php else: ?>
                    <span class="badge badge-purple" style="font-size:0.7rem; font-weight:600;">RADIUS</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span style="font-family:var(--font-mono); font-size:0.85rem; font-weight:600; color:var(--text-primary);">
                    <?= esc($mg['external_group_name']) ?>
                  </span>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--text-secondary);">
                  <?= (int)$mg['priority'] ?>
                </td>
                <td style="text-align:right;">
                  <a href="<?= base_url('roles/group-mappings/' . esc($mg['id']) . '/delete') ?>"
                     class="btn btn-danger btn-sm"
                     onclick="return confirm('Remove mapping for \'<?= esc(addslashes($mg['external_group_name'])) ?>\'?');"
                     title="Remove mapping">
                    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
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
      Save Changes
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

updateCounts();

function openEditRoleGroupMappingModal() {
  document.getElementById('editRoleMappingModal').style.display = 'flex';
}
function closeEditRoleGroupMappingModal() {
  document.getElementById('editRoleMappingModal').style.display = 'none';
}
function updateEditRoleModalLabels(src) {
  const lbl = document.getElementById('editRoleModalGroupLabel');
  const hnt = document.getElementById('editRoleModalGroupHint');
  const inp = document.getElementById('edit_role_modal_group');
  if (src === 'ldap') {
    lbl.innerHTML = 'Directory Group Name / DN <span class="badge-required">*</span>';
    hnt.textContent = 'Common name (CN) or full distinguished name (DN) of the security group.';
    inp.placeholder = 'e.g. Domain Admins or CN=NetworkAdmins,OU=Groups,DC=corp,DC=local';
  } else {
    lbl.innerHTML = 'RADIUS Attribute Value (Filter-Id / Class) <span class="badge-required">*</span>';
    hnt.textContent = 'Attribute string returned by RADIUS Access-Accept packet.';
    inp.placeholder = 'e.g. network_superadmins';
  }
}
</script>

<!-- Modal: Map External Group directly to this Role -->
<div id="editRoleMappingModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:540px; padding:24px; box-sizing:border-box;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h3 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin:0;">Map External Group to <?= esc($role['display_name']) ?></h3>
      <button type="button" onclick="closeEditRoleGroupMappingModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form method="POST" action="<?= base_url('roles/group-mappings/store') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="role_id" value="<?= esc($role['id']) ?>">

      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label" for="edit_role_modal_src">Authentication Provider Source</label>
        <select id="edit_role_modal_src" name="source_type" class="form-control form-select" onchange="updateEditRoleModalLabels(this.value)">
          <option value="ldap">Active Directory / LDAP (AD)</option>
          <option value="radius">RADIUS Authentication (Filter-Id / Class)</option>
        </select>
      </div>

      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label" for="edit_role_modal_group" id="editRoleModalGroupLabel">Directory Group Name / DN <span class="badge-required">*</span></label>
        <input type="text" id="edit_role_modal_group" name="external_group_name" class="form-control mono" required
               placeholder="e.g. Domain Admins or CN=NetworkAdmins,OU=Groups,DC=corp,DC=local" spellcheck="false">
        <span class="form-hint" id="editRoleModalGroupHint">Common name (CN) or full distinguished name (DN) of the security group.</span>
      </div>

      <div class="form-group" style="margin-bottom:20px;">
        <label class="form-label" for="edit_role_modal_priority">Resolution Priority (1 = Highest)</label>
        <input type="number" id="edit_role_modal_priority" name="priority" class="form-control mono" value="10" min="1" max="1000" spellcheck="false">
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeEditRoleGroupMappingModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Map to <?= esc($role['display_name']) ?></button>
      </div>
    </form>
  </div>
</div>

<?= $this->endSection() ?>
