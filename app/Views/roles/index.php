<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span>/</span>
      <span>System</span>
      <span>/</span>
      <span class="current">Roles &amp; Permissions</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px;">
      <div class="page-title-icon">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
      </div>
      <h1 class="page-title">Roles &amp; Permissions</h1>
    </div>
    <p class="page-subtitle">Granular Role-Based Access Control (RBAC) and privilege delegation</p>
  </div>
  <div class="page-header-actions">
    <?php if (session()->get('is_superadmin') || in_array('roles.create', session()->get('permissions') ?? [])): ?>
      <a href="<?= base_url('roles/create') ?>" class="btn btn-primary btn-sm">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Create New Role
      </a>
    <?php endif; ?>
  </div>
</div>

<!-- ============================================================
     Role Metrics Bar
============================================================ -->
<?php
  $totalRoles   = count($roles ?? []);
  $systemRoles  = count(array_filter($roles ?? [], fn($r) => (int)($r['is_system'] ?? 0) === 1));
  $customRoles  = $totalRoles - $systemRoles;
?>
<div class="stats-grid animate-in animate-in-delay-1" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom:24px;">
  <div class="stat-card cyan">
    <div class="stat-icon cyan">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
      </svg>
    </div>
    <div class="stat-value"><?= $totalRoles ?></div>
    <div class="stat-label">Total Defined Roles</div>
  </div>

  <div class="stat-card purple">
    <div class="stat-icon purple">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
      </svg>
    </div>
    <div class="stat-value"><?= $systemRoles ?></div>
    <div class="stat-label">System Protected Roles</div>
  </div>

  <div class="stat-card emerald">
    <div class="stat-icon emerald">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/>
      </svg>
    </div>
    <div class="stat-value"><?= $customRoles ?></div>
    <div class="stat-label">Custom Tenant Roles</div>
  </div>
</div>

<!-- ============================================================
     Roles Table Panel
============================================================ -->
<div class="card card-glass animate-in animate-in-delay-2">
  <div class="card-header">
    <span class="card-title">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
      </svg>
      Defined Security Roles
    </span>
    <div style="display:flex; align-items:center; gap:12px;">
      <input type="text" id="roleSearch" class="form-control" placeholder="Search roles..." style="padding:6px 12px; font-size:0.8rem; width:220px;">
    </div>
  </div>

  <div style="overflow-x:auto;">
    <table class="iface-table" id="rolesTable">
      <thead>
        <tr>
          <th>Role / Slug</th>
          <th>Type</th>
          <th>Description</th>
          <th>Assigned Permissions</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
<?php
  // Build a lookup map of role_id => array of mappings
  $allGroupMappings = array_merge($ldapMappings ?? [], $radiusMappings ?? []);
  $mappingsByRoleId = [];
  foreach ($allGroupMappings as $m) {
      $mappingsByRoleId[$m['role_id']][] = $m;
  }
?>

        <?php if (empty($roles)): ?>
          <tr>
            <td colspan="5" style="text-align:center; padding:32px; color:var(--text-muted);">
              No roles configured. Run the database seed to install default roles.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($roles as $r): ?>
            <tr>
              <td>
                <div style="font-weight:600; font-size:0.92rem; color:var(--text-primary); margin-bottom:2px;">
                  <?= esc($r['display_name']) ?>
                </div>
                <div style="font-family:var(--font-mono); font-size:0.75rem; color:var(--color-cyan); letter-spacing:0.02em;">
                  <?= esc($r['name']) ?>
                </div>
                <?php if (!empty($mappingsByRoleId[$r['id']])): ?>
                  <div style="display:flex; flex-wrap:wrap; gap:4px; margin-top:6px;">
                    <?php foreach ($mappingsByRoleId[$r['id']] as $gm): ?>
                      <?php $srcType = $gm['auth_type'] ?? $gm['source_type'] ?? 'ldap'; $grpName = $gm['external_group'] ?? $gm['external_group_name'] ?? ''; ?>
                      <span class="badge <?= $srcType === 'ldap' ? 'badge-info' : 'badge-purple' ?>" style="font-size:0.68rem;" title="<?= esc($grpName) ?>">
                        <?= $srcType === 'ldap' ? 'AD: ' : 'RADIUS: ' ?><?= esc($grpName) ?>
                      </span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int)($r['is_system'] ?? 0) === 1): ?>
                  <span class="badge badge-purple" title="Protected built-in role">
                    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    System
                  </span>
                <?php else: ?>
                  <span class="badge badge-info" title="User created custom role">
                    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    Custom
                  </span>
                <?php endif; ?>
              </td>
              <td style="color:var(--text-secondary); max-width:320px; font-size:0.83rem;">
                <?= esc($r['description'] ?: 'No description provided') ?>
              </td>
              <td>
                <span class="badge badge-success" style="font-family:var(--font-mono); font-size:0.75rem;">
                  <?= (int)($r['permission_count'] ?? 0) ?> permissions
                </span>
              </td>
              <td style="text-align:right;">
                <div style="display:inline-flex; align-items:center; gap:8px;">
                  <?php if (session()->get('is_superadmin') || in_array('roles.edit', session()->get('permissions') ?? [])): ?>
                    <a href="<?= base_url('roles/edit/' . esc($r['id'])) ?>" class="btn btn-ghost btn-sm" title="Edit Permissions">
                      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                      </svg>
                      Edit
                    </a>
                  <?php endif; ?>

                  <?php if ((int)($r['is_system'] ?? 0) === 0 && (session()->get('is_superadmin') || in_array('roles.delete', session()->get('permissions') ?? []))): ?>
                    <form method="POST" action="<?= base_url('roles/' . esc($r['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete role <?= esc(addslashes($r['display_name'])) ?>? Users assigned to this role will lose its permissions.');">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-danger btn-sm" title="Delete Role">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        Delete
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ============================================================
     External Group to Role Mappings Section
============================================================ -->
<div class="card card-glass animate-in animate-in-delay-3" style="margin-top:24px;">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <span class="card-title">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
      </svg>
      External Group to RBAC Role Mappings (Active Directory & RADIUS)
    </span>
    <div style="display:flex; gap:10px; align-items:center;">
      <a href="<?= base_url('system#ldap') ?>" class="btn btn-ghost btn-sm" title="Configure LDAP and RADIUS providers in System Settings">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        Auth Settings
      </a>
      <button type="button" class="btn btn-primary btn-sm" onclick="openRolesMappingModal()">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
        Map External Group
      </button>
    </div>
  </div>
  <div class="card-body">
    <p style="font-size:0.83rem; color:var(--text-secondary); margin-bottom:16px;">
      Automatically maps external enterprise directory groups (Active Directory OUs, security groups) or RADIUS response attributes (<code style="color:var(--color-cyan);">Filter-Id</code>, <code style="color:var(--color-cyan);">Class</code>) to MicronOS RBAC roles upon sign-in.
    </p>

    <div style="overflow-x:auto;">
      <table class="iface-table">
        <thead>
          <tr>
            <th>Authentication Authority</th>
            <th>External Group / Attribute Identifier</th>
            <th>Mapped Internal Role</th>
            <th style="width:100px;">Priority</th>
            <th style="text-align:right; width:90px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($allGroupMappings)): ?>
            <tr>
              <td colspan="5" style="text-align:center; padding:24px; color:var(--text-muted); font-size:0.85rem;">
                No external group mappings configured yet. Click "Map External Group" to assign AD/LDAP or RADIUS groups to roles.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($allGroupMappings as $m): ?>
              <?php $mType = $m['auth_type'] ?? $m['source_type'] ?? 'ldap'; $mGroup = $m['external_group'] ?? $m['external_group_name'] ?? ''; ?>
              <tr>
                <td>
                  <?php if ($mType === 'ldap'): ?>
                    <span class="badge badge-info" style="font-size:0.72rem; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                      <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                      Active Directory / LDAP
                    </span>
                  <?php else: ?>
                    <span class="badge badge-purple" style="font-size:0.72rem; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                      <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4"/></svg>
                      RADIUS Server
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="font-family:var(--font-mono); font-size:0.88rem; font-weight:600; color:var(--text-primary);">
                    <?= esc($mGroup) ?>
                  </div>
                </td>
                <td>
                  <span class="badge <?= ($m['role_name'] === 'superadmin') ? 'badge-purple' : 'badge-info' ?>" style="font-size:0.75rem;">
                    <?= esc($m['role_display_name'] ?? $m['role_name']) ?>
                  </span>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--text-secondary);">
                  <?= (int)$m['priority'] ?>
                </td>
                <td style="text-align:right;">
                  <form method="POST" action="<?= base_url('roles/group-mappings/' . esc($m['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove group mapping for \'<?= esc(addslashes($mGroup)) ?>\'?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger btn-sm" title="Delete mapping">
                      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ===================================================
     MODAL: Add Group Mapping from Roles Page
=================================================== -->
<div id="rolesMappingModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:540px; padding:24px; box-sizing:border-box;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h3 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin:0;">Map External Directory / RADIUS Group</h3>
      <button type="button" onclick="closeRolesMappingModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form method="POST" action="<?= base_url('roles/group-mappings/store') ?>">
      <?= csrf_field() ?>

      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label" for="roles_modal_src">Authentication Provider Source</label>
        <select id="roles_modal_src" name="source_type" class="form-control form-select" onchange="updateRolesModalLabels(this.value)">
          <option value="ldap">Active Directory / LDAP (AD)</option>
          <option value="radius">RADIUS Authentication (Filter-Id / Class)</option>
        </select>
      </div>

      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label" for="roles_modal_group" id="rolesModalGroupLabel">Directory Group Name / DN <span class="badge-required">*</span></label>
        <input type="text" id="roles_modal_group" name="external_group_name" class="form-control mono" required
               placeholder="e.g. Domain Admins or CN=NetworkAdmins,OU=Groups,DC=corp,DC=local" spellcheck="false">
        <span class="form-hint" id="rolesModalGroupHint">Common name (CN) or full distinguished name (DN) of the security group.</span>
      </div>

      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label" for="roles_modal_role">Target MicronOS RBAC Role <span class="badge-required">*</span></label>
        <select id="roles_modal_role" name="role_id" class="form-control form-select" required>
          <option value="">— Select Target Role —</option>
          <?php foreach ($roles as $role): ?>
            <option value="<?= esc($role['id']) ?>">
              <?= esc($role['display_name']) ?> (<?= esc($role['name']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group" style="margin-bottom:20px;">
        <label class="form-label" for="roles_modal_priority">Resolution Priority (1 = Highest)</label>
        <input type="number" id="roles_modal_priority" name="priority" class="form-control mono" value="10" min="1" max="1000" spellcheck="false">
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeRolesMappingModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Mapping Rule</button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('roleSearch')?.addEventListener('input', function(e) {
  const query = e.target.value.toLowerCase().trim();
  const rows = document.querySelectorAll('#rolesTable tbody tr');
  rows.forEach(row => {
    const text = row.innerText.toLowerCase();
    row.style.display = text.includes(query) ? '' : 'none';
  });
});

function openRolesMappingModal() {
  document.getElementById('rolesMappingModal').style.display = 'flex';
}
function closeRolesMappingModal() {
  document.getElementById('rolesMappingModal').style.display = 'none';
}

function updateRolesModalLabels(src) {
  const lbl = document.getElementById('rolesModalGroupLabel');
  const hnt = document.getElementById('rolesModalGroupHint');
  const inp = document.getElementById('roles_modal_group');
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

<?= $this->endSection() ?>
