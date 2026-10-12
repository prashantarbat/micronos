<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span>/</span>
      <span>System</span>
      <span>/</span>
      <span class="current">Users &amp; Identities</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px;">
      <div class="page-title-icon">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
        </svg>
      </div>
      <h1 class="page-title">Users &amp; Identities</h1>
    </div>
    <p class="page-subtitle">Manage appliance operators, Micron OS accounts, and directory integration</p>
  </div>
  <div class="page-header-actions">
    <a href="<?= base_url('system#ldap') ?>" class="btn btn-ghost btn-sm" title="Configure LDAP and RADIUS authentication servers">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
      </svg>
      Auth Providers
    </a>
    <form method="POST" action="<?= base_url('users/sync-os') ?>" style="display:inline; margin:0;" onsubmit="return confirm('Synchronize Web GUI user accounts with Micron OS system users?');">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" title="Reconcile Web GUI users with Micron OS accounts">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Sync Micron OS
      </button>
    </form>
    <form method="POST" action="<?= base_url('users/import-ldap') ?>" style="display:inline; margin:0;" onsubmit="return confirm('Import and synchronize user accounts from Active Directory / LDAP?');">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" title="Synchronize users from Active Directory / LDAP">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Import LDAP Users
      </button>
    </form>
    <?php if (session()->get('is_superadmin') || in_array('users.create', session()->get('permissions') ?? [])): ?>
      <a href="<?= base_url('users/create') ?>" class="btn btn-primary btn-sm">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
        </svg>
        Add User
      </a>
    <?php endif; ?>
  </div>
</div>

<!-- ============================================================
     User Metrics
============================================================ -->
<?php
  $totalUsers   = count($users ?? []);
  $localUsers   = count(array_filter($users ?? [], fn($u) => empty($u['auth_source']) || $u['auth_source'] === 'local'));
  $osSyncedCount = count(array_filter($users ?? [], fn($u) => !empty($u['os_synced'])));
  $ldapUsers    = count(array_filter($users ?? [], fn($u) => ($u['auth_source'] ?? '') === 'ldap'));
  $radiusUsers  = count(array_filter($users ?? [], fn($u) => ($u['auth_source'] ?? '') === 'radius'));
  $twoFaCount   = count(array_filter($users ?? [], fn($u) => (int)($u['totp_enabled'] ?? 0) === 1));
?>
<div class="stats-grid animate-in animate-in-delay-1" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom:24px;">
  <div class="stat-card cyan">
    <div class="stat-icon cyan">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197"/>
      </svg>
    </div>
    <div class="stat-value"><?= $totalUsers ?></div>
    <div class="stat-label">Total Accounts</div>
  </div>

  <div class="stat-card emerald">
    <div class="stat-icon emerald">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
    </div>
    <div class="stat-value"><?= $osSyncedCount ?></div>
    <div class="stat-label">Micron OS Synced</div>
  </div>

  <div class="stat-card">
    <div class="stat-icon">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
      </svg>
    </div>
    <div class="stat-value"><?= $localUsers ?></div>
    <div class="stat-label">Local Database</div>
  </div>

  <div class="stat-card" style="border-top:3px solid #0284c7;">
    <div class="stat-icon" style="color:#0284c7;">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
      </svg>
    </div>
    <div class="stat-value"><?= $ldapUsers ?></div>
    <div class="stat-label">LDAP / AD Directory</div>
  </div>

  <div class="stat-card purple">
    <div class="stat-icon purple">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4"/>
      </svg>
    </div>
    <div class="stat-value"><?= $radiusUsers ?></div>
    <div class="stat-label">RADIUS Accounts</div>
  </div>
</div>

<!-- ============================================================
     Users Table
============================================================ -->
<div class="card card-glass animate-in animate-in-delay-2">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <span class="card-title">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
      </svg>
      Unified Web GUI & Micron OS Accounts
    </span>

    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <div style="display:flex; gap:4px; background:var(--color-surface-3); padding:3px; border-radius:6px; border:1px solid var(--color-border);">
        <button type="button" class="user-filter-btn active" data-src="all" onclick="filterAuthSource('all', this)" style="padding:4px 10px; font-size:0.75rem; border:none; background:none; color:var(--text-secondary); cursor:pointer; border-radius:4px;">All (<?= $totalUsers ?>)</button>
        <button type="button" class="user-filter-btn" data-src="local" onclick="filterAuthSource('local', this)" style="padding:4px 10px; font-size:0.75rem; border:none; background:none; color:var(--text-secondary); cursor:pointer; border-radius:4px;">Local (<?= $localUsers ?>)</button>
        <button type="button" class="user-filter-btn" data-src="ldap" onclick="filterAuthSource('ldap', this)" style="padding:4px 10px; font-size:0.75rem; border:none; background:none; color:var(--text-secondary); cursor:pointer; border-radius:4px;">LDAP/AD (<?= $ldapUsers ?>)</button>
        <button type="button" class="user-filter-btn" data-src="radius" onclick="filterAuthSource('radius', this)" style="padding:4px 10px; font-size:0.75rem; border:none; background:none; color:var(--text-secondary); cursor:pointer; border-radius:4px;">RADIUS (<?= $radiusUsers ?>)</button>
      </div>
      <input type="text" id="userSearch" class="form-control" placeholder="Search accounts..." style="padding:6px 12px; font-size:0.8rem; width:200px;">
    </div>
  </div>

  <div style="overflow-x:auto;">
    <table class="iface-table" id="usersTable">
      <thead>
        <tr>
          <th>User</th>
          <th>Micron OS Status</th>
          <th>Assigned Roles</th>
          <th>Authentication Source</th>
          <th>2FA</th>
          <th>Last Login</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr>
            <td colspan="7" style="text-align:center; padding:32px; color:var(--text-muted);">
              No users configured.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($users as $u): ?>
            <?php
              $isCurrent = ($u['id'] === session('user_id'));
              $isMinistrator = (strtolower($u['username']) === 'ministrator');
              $src = $u['auth_source'] ?? 'local';
              $isOsSynced = !empty($u['os_synced']);
            ?>
            <tr data-auth-source="<?= esc($src) ?>">
              <td>
                <div style="font-weight:600; font-size:0.92rem; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
                  <?= esc($u['full_name']) ?>
                  <?php if ($isCurrent): ?>
                    <span class="badge badge-info" style="font-size:0.65rem;">You</span>
                  <?php endif; ?>
                </div>
                <div style="font-family:var(--font-mono); font-size:0.75rem; color:var(--color-cyan); margin-top:2px;">
                  @<?= esc($u['username']) ?>
                  <?php if (!empty($u['email'])): ?>
                    &nbsp;·&nbsp; <span style="color:var(--text-muted); font-family:var(--font-sans);"><?= esc($u['email']) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <?php if ($isOsSynced): ?>
                  <span class="badge badge-success" style="font-size:0.72rem; font-weight:600; display:inline-flex; align-items:center; gap:4px;" title="Micron OS account active (UID: <?= esc($u['os_uid'] ?? 1000) ?>, Shell: <?= esc($u['os_shell'] ?? '/bin/bash') ?><?= !empty($u['os_is_admin']) ? ', Sudo Admin' : '' ?>)">
                    <svg width="10" height="10" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                    OS Synced (UID <?= esc($u['os_uid'] ?? '1000') ?>)
                  </span>
                  <div style="font-family:var(--font-mono); font-size:0.68rem; color:var(--text-muted); margin-top:2px;">
                    <?= esc($u['os_shell'] ?? '/bin/bash') ?><?= !empty($u['os_is_admin']) ? ' · sudo' : '' ?>
                  </div>
                <?php else: ?>
                  <span class="badge badge-warning" style="font-size:0.72rem; display:inline-flex; align-items:center; gap:4px;">
                    <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    Not in OS
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex; flex-wrap:wrap; gap:6px;">
                  <?php if (empty($u['roles'])): ?>
                    <span class="badge badge-muted" style="font-size:0.7rem;">None</span>
                  <?php else: ?>
                    <?php foreach ($u['roles'] as $r): ?>
                      <span class="badge <?= ($r['role_name'] === 'superadmin') ? 'badge-purple' : 'badge-info' ?>" style="font-size:0.7rem;">
                        <?= esc($r['role_display_name']) ?>
                      </span>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <?php if ($src === 'ldap'): ?>
                  <span class="badge badge-info" style="font-size:0.72rem; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                    <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    LDAP / AD
                  </span>
                  <?php if (!empty($u['external_group']) || !empty($u['ldap_dn'])): ?>
                    <div style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-muted); margin-top:3px; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= esc($u['ldap_dn'] ?: $u['external_group']) ?>">
                      <?= esc($u['external_group'] ?: $u['ldap_dn']) ?>
                    </div>
                  <?php endif; ?>
                <?php elseif ($src === 'radius'): ?>
                  <span class="badge badge-purple" style="font-size:0.72rem; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                    <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4"/></svg>
                    RADIUS
                  </span>
                  <?php if (!empty($u['external_group'])): ?>
                    <div style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-muted); margin-top:3px;">
                      <?= esc($u['external_group']) ?>
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="badge badge-muted" style="font-size:0.72rem; text-transform:uppercase;">
                    Local DB
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int)($u['totp_enabled'] ?? 0) === 1): ?>
                  <span class="badge badge-success" style="font-size:0.7rem;">Enabled</span>
                <?php else: ?>
                  <span class="badge badge-muted" style="font-size:0.7rem;">Disabled</span>
                <?php endif; ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);">
                <?= $u['last_login_at'] ? esc($u['last_login_at']) : '<span style="color:var(--text-muted);">Never</span>' ?>
              </td>
              <td style="text-align:right;">
                <div style="display:inline-flex; align-items:center; gap:8px;">
                  <?php if (session()->get('is_superadmin') || in_array('users.password.reset', session()->get('permissions') ?? [])): ?>
                    <button type="button" class="btn btn-ghost btn-sm" title="Reset Password (Web GUI & Micron OS)" onclick="promptResetPassword('<?= esc($u['id']) ?>', '<?= esc(addslashes($u['username'])) ?>')">
                      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                      </svg>
                    </button>
                  <?php endif; ?>

                  <?php if (session()->get('is_superadmin') || in_array('users.edit', session()->get('permissions') ?? [])): ?>
                    <a href="<?= base_url('users/edit/' . esc($u['id'])) ?>" class="btn btn-ghost btn-sm" title="Edit User">
                      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                      </svg>
                      Edit
                    </a>
                  <?php endif; ?>

                  <?php if (! $isCurrent && ! $isMinistrator && (session()->get('is_superadmin') || in_array('users.delete', session()->get('permissions') ?? []))): ?>
                    <form method="POST" action="<?= base_url('users/' . esc($u['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Delete user @<?= esc(addslashes($u['username'])) ?> from Web GUI and Micron OS?');">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-danger btn-sm" title="Delete User">
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

<!-- Hidden Password Reset Form -->
<form id="pwResetForm" method="POST" style="display:none;">
  <?= csrf_field() ?>
  <input type="password" name="new_password" id="pwResetInput">
</form>

<script>
let currentAuthFilter = 'all';

function filterAuthSource(source, btn) {
  currentAuthFilter = source;
  document.querySelectorAll('.user-filter-btn').forEach(b => {
    b.classList.remove('active');
    b.style.background = 'none';
    b.style.color = 'var(--text-secondary)';
  });
  btn.classList.add('active');
  btn.style.background = 'var(--color-surface-1)';
  btn.style.color = 'var(--color-cyan)';

  applyUserFilters();
}

function applyUserFilters() {
  const query = document.getElementById('userSearch')?.value.toLowerCase().trim() || '';
  document.querySelectorAll('#usersTable tbody tr[data-auth-source]').forEach(row => {
    const src = row.getAttribute('data-auth-source') || 'local';
    const matchSrc = (currentAuthFilter === 'all') || (src === currentAuthFilter);
    const matchText = !query || row.innerText.toLowerCase().includes(query);
    row.style.display = (matchSrc && matchText) ? '' : 'none';
  });
}

function promptResetPassword(userId, username) {
  const newPass = prompt(`Enter new password for user @${username} (will sync to Micron OS):`);
  if (!newPass) return;
  if (newPass.length < 6) {
    alert('Password must be at least 6 characters.');
    return;
  }
  const form = document.getElementById('pwResetForm');
  form.action = '<?= base_url('users') ?>/' + userId + '/reset-pw';
  document.getElementById('pwResetInput').value = newPass;
  form.submit();
}

document.getElementById('userSearch')?.addEventListener('input', applyUserFilters);
</script>

<?= $this->endSection() ?>
