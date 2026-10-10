<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <h1 class="page-title">Edit User: <?= esc($user['username']) ?></h1>
      <p class="page-subtitle">Update unified Web GUI and Micron OS credentials, shell, and privileges</p>
    </div>
    <a href="<?= base_url('users') ?>" class="btn btn-ghost">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
      </svg>
      Back to Users
    </a>
  </div>
</div>

<form method="POST" action="<?= base_url('users/update/' . esc($user['id'])) ?>" class="animate-in animate-in-delay-1">
  <?= csrf_field() ?>

  <div style="display:grid; grid-template-columns: 1fr 1fr; gap:24px;">

    <!-- Account Details -->
    <div class="card card-glass">
      <div class="card-header">
        <span class="card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
          </svg>
          Account Attributes & OS Configuration
        </span>
      </div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label">Username</label>
          <input type="text" class="form-control" value="<?= esc($user['username']) ?>" readonly
                 style="opacity:0.65; cursor:not-allowed; font-family:var(--font-mono);">
          <div class="form-hint">System username identifier is immutable.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="full_name">Full Name <span style="color:var(--color-red);">*</span></label>
          <input type="text" id="full_name" name="full_name" class="form-control" required
                 value="<?= esc(old('full_name', $user['full_name'])) ?>">
          <div class="form-hint">Stored in database and updated in Linux GECOS field.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="email">Email Address</label>
          <input type="email" id="email" name="email" class="form-control"
                 placeholder="e.g. user@example.com" value="<?= esc(old('email', $user['email'])) ?>">
        </div>

        <div class="form-group">
          <label class="form-label" for="auth_source">Authentication Authority</label>
          <select id="auth_source" name="auth_source" class="form-control form-select" onchange="toggleEditAuthSource(this.value)">
            <option value="local" <?= ($user['auth_source'] ?? 'local') === 'local' ? 'selected' : '' ?>>Local Database & Micron OS (Unified)</option>
            <option value="ldap" <?= ($user['auth_source'] ?? '') === 'ldap' ? 'selected' : '' ?>>LDAP / Microsoft Active Directory</option>
            <option value="radius" <?= ($user['auth_source'] ?? '') === 'radius' ? 'selected' : '' ?>>RADIUS Authentication Server</option>
          </select>
          <div class="form-hint">Determines where password authentication and identity are verified.</div>
        </div>

        <div class="form-group" id="group_ldap_dn" style="display:none;">
          <label class="form-label" for="ldap_dn">Directory Distinguished Name (DN)</label>
          <input type="text" id="ldap_dn" name="ldap_dn" class="form-control mono"
                 value="<?= esc(old('ldap_dn', $user['ldap_dn'] ?? ''))?>" spellcheck="false"
                 placeholder="CN=User,OU=Users,DC=corp,DC=local">
          <div class="form-hint">LDAP Directory DN matching this account.</div>
        </div>

        <div class="form-group" id="group_external_group" style="display:none;">
          <label class="form-label" for="external_group" id="external_group_label">External Security Group / Attribute</label>
          <input type="text" id="external_group" name="external_group" class="form-control mono"
                 value="<?= esc(old('external_group', $user['external_group'] ?? ''))?>" spellcheck="false"
                 placeholder="e.g. Domain Admins or Filter-Id">
          <div class="form-hint" id="external_group_hint">Directory group membership or RADIUS Filter-Id string for dynamic role resolution.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="shell">Micron OS Login Shell</label>
          <select id="shell" name="shell" class="form-control form-select" style="font-family:var(--font-mono); font-size:0.85rem;">
            <?php $currShell = old('shell', $user['shell'] ?? '/bin/bash'); ?>
            <option value="/bin/bash" <?= ($currShell === '/bin/bash') ? 'selected' : '' ?>>/bin/bash (Default Interactive Shell)</option>
            <option value="/usr/local/bin/microctl" <?= ($currShell === '/usr/local/bin/microctl') ? 'selected' : '' ?>>/usr/local/bin/microctl (Appliance Console Manager)</option>
            <option value="/bin/sh" <?= ($currShell === '/bin/sh') ? 'selected' : '' ?>>/bin/sh (POSIX Shell)</option>
            <option value="/usr/sbin/nologin" <?= ($currShell === '/usr/sbin/nologin') ? 'selected' : '' ?>>/usr/sbin/nologin (Web GUI Only / No SSH Access)</option>
          </select>
          <div class="form-hint">Configured system login shell in /etc/passwd.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="ssh_keys">SSH Authorized Keys</label>
          <textarea id="ssh_keys" name="ssh_keys" class="form-control mono" rows="3"
                    placeholder="ssh-ed25519 AAAAC3NzaC... operator@workstation"
                    style="font-size:0.75rem; line-height:1.4; resize:vertical;"><?= esc(old('ssh_keys', $user['ssh_keys'] ?? '')) ?></textarea>
          <div class="form-hint">Public keys automatically written to ~/.ssh/authorized_keys on the appliance.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="password">Change Password</label>
          <input type="password" id="password" name="password" class="form-control" minlength="6" autocomplete="new-password"
                 placeholder="Leave blank to keep existing password" style="font-family:var(--font-mono);">
          <div class="form-hint" id="password_hint">Entering a new password immediately synchronizes both Web GUI and Micron OS.</div>
        </div>

        <?php if ((int)($user['totp_enabled'] ?? 0) === 1): ?>
          <div class="form-group" style="margin-bottom:0; padding:12px; background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.2); border-radius:var(--radius-md);">
            <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin:0;">
              <input type="checkbox" name="reset_totp" value="1" style="accent-color:var(--color-red); width:16px; height:16px;">
              <div>
                <div style="font-weight:600; font-size:0.85rem; color:var(--color-red);">Reset 2FA Authentication</div>
                <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
                  Disable the user's existing TOTP authenticator app and require re-enrollment.
                </div>
              </div>
            </label>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Assigned Roles -->
    <div class="card card-glass">
      <div class="card-header">
        <span class="card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
          </svg>
          Assigned Security Roles & Linux Privileges
        </span>
      </div>
      <div class="card-body">
        <p style="font-size:0.8rem; color:var(--text-secondary); margin-bottom:16px;">
          Privileges are computed dynamically from all selected roles. Administrative roles (such as <strong>Super Administrator</strong>) automatically grant membership in the system <code>sudo</code> group.
        </p>

        <div style="display:flex; flex-direction:column; gap:12px;">
          <?php foreach ($allRoles as $r): ?>
            <?php
              $isAssigned = in_array($r['id'], $selectedRoles ?? []);
              $isSuperadminMinistrator = (strtolower($user['username']) === 'ministrator' && $r['name'] === 'superadmin');
            ?>
            <label style="display:flex; align-items:flex-start; gap:12px; padding:12px; background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-md); cursor:pointer;">
              <input type="checkbox" name="roles[]" value="<?= esc($r['id']) ?>"
                     <?= $isAssigned ? 'checked' : '' ?>
                     <?= $isSuperadminMinistrator ? 'onclick="return false;"' : '' ?>
                     style="accent-color:var(--color-cyan); width:18px; height:18px; margin-top:2px; cursor:pointer;">
              <div style="flex:1;">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                  <span style="font-weight:600; font-size:0.88rem; color:var(--text-primary);">
                    <?= esc($r['display_name']) ?>
                  </span>
                  <?php if ((int)($r['is_system'] ?? 0) === 1): ?>
                    <span class="badge badge-purple" style="font-size:0.65rem;">System</span>
                  <?php else: ?>
                    <span class="badge badge-info" style="font-size:0.65rem;">Custom</span>
                  <?php endif; ?>
                </div>
                <div style="font-family:var(--font-mono); font-size:0.73rem; color:var(--color-cyan); margin-top:2px;">
                  <?= esc($r['name']) ?> &nbsp;·&nbsp; <?= (int)($r['permission_count'] ?? 0) ?> perms
                  <?php if (in_array(strtolower($r['name']), ['superadmin', 'admin', 'administrator'])): ?>
                    &nbsp;·&nbsp; <span style="color:var(--color-amber);">Grants Linux sudo</span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($r['description'])): ?>
                  <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">
                    <?= esc($r['description']) ?>
                  </div>
                <?php endif; ?>
                <?php if ($isSuperadminMinistrator): ?>
                  <div style="font-size:0.72rem; color:var(--color-amber); margin-top:4px;">
                    Protected: Superadmin role cannot be revoked from the default Ministrator account.
                  </div>
                <?php endif; ?>
              </div>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>

  <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:24px; margin-bottom:40px;">
    <a href="<?= base_url('users') ?>" class="btn btn-ghost">Cancel</a>
    <button type="submit" class="btn btn-primary" style="min-width:140px;">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
      </svg>
      Save Changes
    </button>
  </div>
</form>

<script>
function toggleEditAuthSource(src) {
  const ldapDnGrp = document.getElementById('group_ldap_dn');
  const extGrp = document.getElementById('group_external_group');
  const pwdHint = document.getElementById('password_hint');

  if (src === 'ldap') {
    ldapDnGrp.style.display = 'block';
    extGrp.style.display = 'block';
    document.getElementById('external_group_label').textContent = 'Active Directory / LDAP Group';
    document.getElementById('external_group_hint').textContent = 'e.g. Domain Admins or CN=NetworkAdmins,OU=Groups,DC=corp,DC=local';
    pwdHint.textContent = 'Optional: Leave blank to use Active Directory password. Enter only to set local override.';
  } else if (src === 'radius') {
    ldapDnGrp.style.display = 'none';
    extGrp.style.display = 'block';
    document.getElementById('external_group_label').textContent = 'Expected RADIUS Filter-Id / Class Attribute';
    document.getElementById('external_group_hint').textContent = 'e.g. network_superadmins (matches configured RADIUS attribute mapping)';
    pwdHint.textContent = 'Optional: Authentication is handled by RADIUS. Enter only to set local override.';
  } else {
    ldapDnGrp.style.display = 'none';
    extGrp.style.display = 'none';
    pwdHint.textContent = 'Entering a new password immediately synchronizes both Web GUI and Micron OS.';
  }
}
toggleEditAuthSource(document.getElementById('auth_source').value);
</script>

<?= $this->endSection() ?>
